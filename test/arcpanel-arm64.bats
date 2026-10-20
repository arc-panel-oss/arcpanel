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
