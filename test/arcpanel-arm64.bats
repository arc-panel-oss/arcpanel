#!/usr/bin/env bats
# arm64 packages (E47, docs/features/specs/2026-10-20-003-e47-arm64-packages-design.md §6):
# the parallel job count of the build script, the arm64 guard of the installers
# and the architectures of the APT repository. Needs no server: runs from the
# repository root, locally and in the lint workflow.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"

# Loads the function NAME from FILE without running the rest of the script
load_function() {
	local file="$1" name="$2" code
	code="$(tr -d '\r' < "$REPO_ROOT/$file" | sed -n "/^${name}() {/,/^}/p")"
	[ -n "$code" ] || {
		echo "$file has no function $name" >&2
		return 1
	}
	eval "$code"
}

setup() {
	TMP="$(mktemp -d)"
}

teardown() {
	rm -rf "$TMP"
}

#----------------------------------------------------------#
#   Parallel compile jobs (src/arcpanel-build.sh)          #
#----------------------------------------------------------#

@test "build: the cpu cores line of an x86 cpuinfo sets the job count" {
	load_function src/arcpanel-build.sh detect_num_cpus
	printf 'processor\t: 0\ncpu cores\t: 4\n\nprocessor\t: 1\ncpu cores\t: 4\n' > "$TMP/cpuinfo"
	unset NUM_CPUS
	run detect_num_cpus "$TMP/cpuinfo"
	assert_success
	assert_output 4
}

@test "build: an arm64 cpuinfo without cpu cores falls back to nproc" {
	load_function src/arcpanel-build.sh detect_num_cpus
	printf 'processor\t: 0\nBogoMIPS\t: 50.00\nFeatures\t: fp asimd\nCPU implementer\t: 0x41\n' > "$TMP/cpuinfo"
	unset NUM_CPUS
	run detect_num_cpus "$TMP/cpuinfo"
	assert_success
	assert_output "$(nproc)"
}

@test "build: a NUM_CPUS set by the caller wins" {
	load_function src/arcpanel-build.sh detect_num_cpus
	printf 'cpu cores\t: 4\n' > "$TMP/cpuinfo"
	NUM_CPUS=2 run detect_num_cpus "$TMP/cpuinfo"
	assert_success
	assert_output 2
}

@test "build: the job count is never empty" {
	load_function src/arcpanel-build.sh detect_num_cpus
	: > "$TMP/cpuinfo"
	unset NUM_CPUS
	run detect_num_cpus "$TMP/cpuinfo"
	assert_success
	[[ "$output" =~ ^[1-9][0-9]*$ ]]
}

#----------------------------------------------------------#
#   arm64 guard of the installers                          #
#----------------------------------------------------------#

INSTALLERS="install/arcpanel-install-debian.sh install/arcpanel-install-ubuntu.sh"

@test "installers: stop on aarch64 without the escape variable" {
	local installer
	for installer in $INSTALLERS; do
		load_function "$installer" check_result
		load_function "$installer" check_install_arch
		unset ARCPANEL_ALLOW_UNSUPPORTED_ARCH
		run check_install_arch aarch64
		assert_failure 1
		assert_output --partial "arm64 (aarch64) is not supported for now"
	done
}

@test "installers: continue on aarch64 with a warning when the escape variable is yes" {
	local installer
	for installer in $INSTALLERS; do
		load_function "$installer" check_result
		load_function "$installer" check_install_arch
		ARCPANEL_ALLOW_UNSUPPORTED_ARCH=yes run check_install_arch aarch64
		assert_success
		assert_output --partial "WARNING: installing on arm64 (aarch64), which is not supported."
	done
}

@test "installers: only the value yes opens the escape" {
	local installer
	for installer in $INSTALLERS; do
		load_function "$installer" check_result
		load_function "$installer" check_install_arch
		ARCPANEL_ALLOW_UNSUPPORTED_ARCH=1 run check_install_arch aarch64
		assert_failure 1
	done
}

@test "installers: x86_64 passes silently" {
	local installer
	for installer in $INSTALLERS; do
		load_function "$installer" check_result
		load_function "$installer" check_install_arch
		unset ARCPANEL_ALLOW_UNSUPPORTED_ARCH
		run check_install_arch x86_64
		assert_success
		assert_output ""
	done
}

@test "installers: the guard runs before any change to the system" {
	local installer guard first_change
	for installer in $INSTALLERS; do
		guard="$(tr -d '\r' < "$REPO_ROOT/$installer" | grep -n '^check_install_arch "\$architecture"$' | cut -d: -f1)"
		first_change="$(tr -d '\r' < "$REPO_ROOT/$installer" | grep -n '80-retries' | head -n 1 | cut -d: -f1)"
		[ -n "$guard" ] && [ -n "$first_change" ]
		[ "$guard" -lt "$first_change" ]
	done
}

#----------------------------------------------------------#
#   Architectures of the APT repository                    #
#   (.github/scripts/build-apt-repo.sh)                    #
#----------------------------------------------------------#

# Builds an empty package NAME for ARCH into DIR
fake_deb() {
	local dir="$1" name="$2" arch="$3" root
	root="$TMP/pkg-$name-$arch"
	mkdir -p "$root/DEBIAN" "$dir"
	printf 'Package: %s\nVersion: 1.0.0\nArchitecture: %s\nMaintainer: Test <test@example.com>\nDescription: test\n' \
		"$name" "$arch" > "$root/DEBIAN/control"
	dpkg-deb --build --root-owner-group "$root" "$dir/${name}_1.0.0_${arch}.deb" > /dev/null
}

apt_setup() {
	command -v dpkg-deb > /dev/null || skip "dpkg-deb is not installed"
	load_function .github/scripts/build-apt-repo.sh codename_architectures
}

@test "apt repo: an amd64-only codename lists amd64" {
	apt_setup
	fake_deb "$TMP/debs/noble" arcpanel amd64
	fake_deb "$TMP/debs/noble" arcpanel-php amd64
	run codename_architectures "$TMP/debs/noble"
	assert_success
	assert_output amd64
}

@test "apt repo: a codename with amd64 and arm64 packages lists both" {
	apt_setup
	fake_deb "$TMP/debs/noble" arcpanel amd64
	fake_deb "$TMP/debs/noble" arcpanel arm64
	fake_deb "$TMP/debs/noble" arcpanel-php arm64
	run codename_architectures "$TMP/debs/noble"
	assert_success
	assert_output "amd64 arm64"
}

@test "apt repo: architecture-independent packages add no architecture" {
	apt_setup
	fake_deb "$TMP/debs/noble" arcpanel arm64
	fake_deb "$TMP/debs/noble" arcpanel-docs all
	run codename_architectures "$TMP/debs/noble"
	assert_success
	assert_output arm64
}

@test "apt repo: a codename without packages fails" {
	apt_setup
	mkdir -p "$TMP/debs/noble"
	run codename_architectures "$TMP/debs/noble"
	assert_failure
	assert_output --partial "no package for noble"
}
