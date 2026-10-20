#!/usr/bin/env bats
# The APT repository that keeps every version (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §5.2):
# .github/scripts/build-apt-repo.sh with generated packages and a throwaway
# signing key. Needs no server: runs from the repository root, locally and in
# the lint workflow, and skips without dpkg-deb, apt-ftparchive or gpg.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
BUILD="$REPO_ROOT/.github/scripts/build-apt-repo.sh"

setup_file() {
	command -v gpg > /dev/null || return 0
	export GNUPGHOME="$BATS_FILE_TMPDIR/gnupg"
	install -d -m 700 "$GNUPGHOME"
	gpg --batch --passphrase '' --quick-gen-key 'ArcPanel Test <test@example.invalid>' ed25519 sign never 2> /dev/null
	KEY_ID="$(gpg --list-secret-keys --with-colons | awk -F: '/^fpr/ {print $10; exit}')"
	export KEY_ID
}

setup() {
	for tool in dpkg-deb apt-ftparchive gpg; do
		command -v "$tool" > /dev/null || skip "$tool is not installed"
	done
	TMP="$(mktemp -d)"
}

teardown() {
	[ -z "${TMP:-}" ] || rm -rf "$TMP"
}

# Builds package NAME VERSION ARCH into DIR; DESCRIPTION changes the file's bytes
fake_deb() {
	local dir="$1" name="$2" version="$3" arch="$4" description="${5:-test}" root
	root="$(mktemp -d "$TMP/pkg.XXXXXX")"
	mkdir -p "$root/DEBIAN" "$dir"
	printf 'Package: %s\nVersion: %s\nArchitecture: %s\nMaintainer: Test <test@example.invalid>\nDescription: %s\n' \
		"$name" "$version" "$arch" "$description" > "$root/DEBIAN/control"
	dpkg-deb --build --root-owner-group "$root" "$dir/${name}_${version}_${arch}.deb" > /dev/null
}

# Versions of PACKAGE in the index of CODENAME and ARCH of the repository REPO
versions() {
	awk -v p="$3" '/^Package: / {n = $2} /^Version: / && n == p {print $2}' \
		"$1/dists/$2/main/binary-$4/Packages" | sort -V | tr '\n' ' ' | sed 's/ $//'
}

@test "apt repo: a first release builds a signed repository with its key" {
	fake_deb "$TMP/debs/noble" arcpanel 2.2.1-1 amd64
	run bash "$BUILD" "$TMP/out" "$KEY_ID" - "$TMP/debs"
	assert_success
	assert_output --partial "1 packages"
	run versions "$TMP/out" noble arcpanel amd64
	assert_output 2.2.1-1
	run gpg --verify "$TMP/out/dists/noble/InRelease"
	assert_success
	[ -s "$TMP/out/arcpanel.gpg" ]
}

@test "apt repo: a second release keeps the versions of the first" {
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	fake_deb "$TMP/debs2/noble" arcpanel 2.2.2-1 amd64
	run bash "$BUILD" "$TMP/out2" "$KEY_ID" "$TMP/out1" "$TMP/debs2"
	assert_success
	run versions "$TMP/out2" noble arcpanel amd64
	assert_output "2.2.1-1 2.2.2-1"
	[ -f "$TMP/out2/pool/main/a/arcpanel/arcpanel_2.2.1-1_amd64.deb" ]
}

@test "apt repo: a rebuilt package with a published version keeps the pool's file" {
	fake_deb "$TMP/debs1/noble" arcpanel-php 8.5.11-1 amd64 first
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	fake_deb "$TMP/debs2/noble" arcpanel-php 8.5.11-1 amd64 rebuilt
	run bash "$BUILD" "$TMP/out2" "$KEY_ID" "$TMP/out1" "$TMP/debs2"
	assert_success
	assert_output --partial "keeping pool file arcpanel-php_8.5.11-1_amd64.deb for noble, new build differs"
	cmp "$TMP/out1/pool/main/a/arcpanel-php/arcpanel-php_8.5.11-1_amd64.deb" \
		"$TMP/out2/pool/main/a/arcpanel-php/arcpanel-php_8.5.11-1_amd64.deb"
}

@test "apt repo: an identical rebuilt package is skipped without a warning" {
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	run bash "$BUILD" "$TMP/out2" "$KEY_ID" "$TMP/out1" "$TMP/debs1"
	assert_success
	refute_output --partial "keeping pool file"
	run versions "$TMP/out2" noble arcpanel amd64
	assert_output 2.2.1-1
}

@test "apt repo: of two new folders with the same version the first wins" {
	fake_deb "$TMP/a/noble" arcpanel-php 8.5.11-1 amd64 first
	fake_deb "$TMP/b/noble" arcpanel-php 8.5.11-1 amd64 second
	run bash "$BUILD" "$TMP/out" "$KEY_ID" - "$TMP/a" "$TMP/b"
	assert_success
	assert_output --partial "keeping pool file"
	cmp "$TMP/a/noble/arcpanel-php_8.5.11-1_amd64.deb" "$TMP/out/pool/main/a/arcpanel-php/arcpanel-php_8.5.11-1_amd64.deb"
}

@test "apt repo: arm64 stays when a later release is amd64-only" {
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 arm64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	fake_deb "$TMP/debs2/noble" arcpanel 2.3.0~beta.1-1 amd64
	run bash "$BUILD" "$TMP/out2" "$KEY_ID" "$TMP/out1" "$TMP/debs2"
	assert_success
	run grep '^Architectures:' "$TMP/out2/dists/noble/Release"
	assert_output "Architectures: amd64 arm64"
	run versions "$TMP/out2" noble arcpanel arm64
	assert_output 2.2.1-1
	run versions "$TMP/out2" noble arcpanel amd64
	assert_output "2.2.1-1 2.3.0~beta.1-1"
}

@test "apt repo: a codename without new packages keeps its index" {
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	fake_deb "$TMP/debs1/jammy" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	fake_deb "$TMP/debs2/noble" arcpanel 2.2.2-1 amd64
	run bash "$BUILD" "$TMP/out2" "$KEY_ID" "$TMP/out1" "$TMP/debs2"
	assert_success
	run versions "$TMP/out2" jammy arcpanel amd64
	assert_output 2.2.1-1
	run gpg --verify "$TMP/out2/dists/jammy/InRelease"
	assert_success
}

@test "apt repo: an index entry without its pool file fails" {
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	rm "$TMP/out1/pool/main/a/arcpanel/arcpanel_2.2.1-1_amd64.deb"
	fake_deb "$TMP/debs2/noble" arcpanel 2.2.2-1 amd64
	run bash "$BUILD" "$TMP/out2" "$KEY_ID" "$TMP/out1" "$TMP/debs2"
	assert_failure
	assert_output --partial "is in the index of noble but not in the pool"
}

@test "apt repo: the output folder may not be the current repository" {
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	run bash "$BUILD" "$TMP/out1" "$KEY_ID" "$TMP/out1" "$TMP/debs1"
	assert_failure
	assert_output --partial "must not be the current repository"
	[ -f "$TMP/out1/pool/main/a/arcpanel/arcpanel_2.2.1-1_amd64.deb" ]
}

@test "apt repo: an empty signing key id fails" {
	fake_deb "$TMP/debs/noble" arcpanel 2.2.1-1 amd64
	run bash "$BUILD" "$TMP/out" "" - "$TMP/debs"
	assert_failure
	assert_output --partial "signing key id is empty"
}

@test "apt repo: nothing to publish fails" {
	mkdir -p "$TMP/debs"
	run bash "$BUILD" "$TMP/out" "$KEY_ID" - "$TMP/debs"
	assert_failure
	assert_output --partial "no package to publish"
}
