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

#----------------------------------------------------------#
#   Rebuild from the GitHub Releases                       #
#   (.github/scripts/rebuild-apt-repo.sh build|compare)    #
#----------------------------------------------------------#

REBUILD="$REPO_ROOT/.github/scripts/rebuild-apt-repo.sh"

# A signed manifest in DIR that names GENERATED
signed_manifest() {
	local dir="$1" generated="$2"
	mkdir -p "$dir"
	printf '{"format": 1, "channel": "stable", "generated": "%s", "releases": []}\n' "$generated" > "$dir/releases.json"
	gpg --batch --yes --local-user "$KEY_ID" --detach-sign -o "$dir/releases.json.sig" "$dir/releases.json"
}

# Two releases as the host received them and as the GitHub Releases keep them
two_releases() {
	fake_deb "$TMP/gh/stable/0001-v2.2.1/noble" arcpanel 2.2.1-1 amd64
	fake_deb "$TMP/gh/stable/0001-v2.2.1/noble" arcpanel-php 8.5.11-1 amd64 first
	signed_manifest "$TMP/gh/stable/0001-v2.2.1" 2026-10-20T10:00:00Z
	fake_deb "$TMP/gh/stable/0002-v2.2.2/noble" arcpanel 2.2.2-1 amd64
	fake_deb "$TMP/gh/stable/0002-v2.2.2/noble" arcpanel-php 8.5.11-1 amd64 rebuilt
	signed_manifest "$TMP/gh/stable/0002-v2.2.2" 2026-10-23T10:00:00Z
	bash "$BUILD" "$TMP/host1" "$KEY_ID" - "$TMP/gh/stable/0001-v2.2.1"
	bash "$BUILD" "$TMP/host" "$KEY_ID" "$TMP/host1" "$TMP/gh/stable/0002-v2.2.2"
	cp "$TMP/gh/stable/0002-v2.2.2/releases.json"* "$TMP/host/"
}

@test "rebuild: the releases give the same repository as the releases published one by one" {
	command -v jq > /dev/null || skip "jq is not installed"
	two_releases
	run bash "$REBUILD" build "$TMP/gh" "$TMP/rebuilt" "$KEY_ID"
	assert_success
	assert_output --partial "stable from 2 releases, manifest of 0002-v2.2.2"
	run bash "$REBUILD" compare "$TMP/rebuilt/stable" "$TMP/host"
	assert_success
	assert_output --partial "same pool (3 packages)"
	assert_output --partial "same index entries"
	assert_output --partial "same manifest"
}

@test "rebuild: compare reports a changed package and a changed manifest" {
	command -v jq > /dev/null || skip "jq is not installed"
	two_releases
	bash "$REBUILD" build "$TMP/gh" "$TMP/rebuilt" "$KEY_ID"
	fake_deb "$TMP/other" arcpanel 2.2.2-1 amd64 tampered
	# The pools share files through hard links: replace the file, do not write into it
	rm "$TMP/host/pool/main/a/arcpanel/arcpanel_2.2.2-1_amd64.deb"
	cp "$TMP/other/arcpanel_2.2.2-1_amd64.deb" "$TMP/host/pool/main/a/arcpanel/"
	signed_manifest "$TMP/host" 2026-10-24T00:00:00Z
	run bash "$REBUILD" compare "$TMP/rebuilt/stable" "$TMP/host"
	assert_failure
	assert_output --partial "the pools differ"
	assert_output --partial "the manifests differ"
}

@test "rebuild: a manifest whose signature does not verify is refused" {
	command -v jq > /dev/null || skip "jq is not installed"
	two_releases
	sed -i 's/2026-10-23/2026-10-29/' "$TMP/gh/stable/0002-v2.2.2/releases.json"
	run bash "$REBUILD" build "$TMP/gh" "$TMP/rebuilt" "$KEY_ID"
	assert_failure
	assert_output --partial "does not verify"
}

#----------------------------------------------------------#
#   Copies to and from the bucket (.github/scripts/apt-host.sh), #
#   with a local folder as the bucket                      #
#----------------------------------------------------------#

HOST_SCRIPT="$REPO_ROOT/.github/scripts/apt-host.sh"

@test "apt host: a push never deletes from the pool and replaces the indexes" {
	command -v rclone > /dev/null || skip "rclone is not installed"
	fake_deb "$TMP/debs1/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out1" "$KEY_ID" - "$TMP/debs1"
	signed_manifest "$TMP/out1" 2026-10-20T10:00:00Z
	bash "$HOST_SCRIPT" push "$TMP/out1" "$TMP/host"
	# A repository without the 2.2.1 package: the host keeps its file, the index drops it
	fake_deb "$TMP/debs2/noble" arcpanel 2.2.2-1 amd64
	bash "$BUILD" "$TMP/out2" "$KEY_ID" - "$TMP/debs2"
	signed_manifest "$TMP/out2" 2026-10-23T10:00:00Z
	run bash "$HOST_SCRIPT" push "$TMP/out2" "$TMP/host"
	assert_success
	[ -f "$TMP/host/pool/main/a/arcpanel/arcpanel_2.2.1-1_amd64.deb" ]
	grep -q 'ArcPanel APT repository' "$TMP/host/index.html"
	run versions "$TMP/host" noble arcpanel amd64
	assert_output 2.2.2-1
	run jq -r .generated "$TMP/host/releases.json"
	assert_output 2026-10-23T10:00:00Z
	run gpg --verify "$TMP/host/dists/noble/InRelease"
	assert_success
}

@test "apt host: a pull of the stable channel leaves the beta repository out" {
	command -v rclone > /dev/null || skip "rclone is not installed"
	fake_deb "$TMP/debs/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out" "$KEY_ID" - "$TMP/debs"
	signed_manifest "$TMP/out" 2026-10-20T10:00:00Z
	bash "$HOST_SCRIPT" push "$TMP/out" "$TMP/host"
	bash "$HOST_SCRIPT" push "$TMP/out" "$TMP/host/beta"
	touch "$TMP/host/index.html"
	run bash "$HOST_SCRIPT" pull "$TMP/host" "$TMP/current"
	assert_success
	[ -f "$TMP/current/releases.json.sig" ]
	[ -d "$TMP/current/pool" ]
	[ ! -e "$TMP/current/beta" ]
	[ ! -e "$TMP/current/index.html" ]
}

@test "apt host: a manifest push needs a signed manifest" {
	command -v rclone > /dev/null || skip "rclone is not installed"
	mkdir -p "$TMP/new"
	echo '{}' > "$TMP/new/releases.json"
	run bash "$HOST_SCRIPT" push-manifest "$TMP/new" "$TMP/host"
	assert_failure
	assert_output --partial "has no signed releases.json"
}

@test "apt host: a pull of a channel never published leaves an empty copy" {
	command -v rclone > /dev/null || skip "rclone is not installed"
	mkdir -p "$TMP/host" "$TMP/current/pool" "$TMP/current/dists"
	echo stale > "$TMP/current/releases.json"
	run bash "$HOST_SCRIPT" pull "$TMP/host/beta" "$TMP/current"
	assert_success
	assert_output --partial "nothing published"
	[ ! -e "$TMP/current/pool" ]
	[ ! -e "$TMP/current/dists" ]
	[ ! -e "$TMP/current/releases.json" ]
}

@test "apt host: a manifest pull copies the manifest only" {
	command -v rclone > /dev/null || skip "rclone is not installed"
	fake_deb "$TMP/debs/noble" arcpanel 2.2.1-1 amd64
	bash "$BUILD" "$TMP/out" "$KEY_ID" - "$TMP/debs"
	signed_manifest "$TMP/out" 2026-10-20T10:00:00Z
	bash "$HOST_SCRIPT" push "$TMP/out" "$TMP/host"
	run bash "$HOST_SCRIPT" pull-manifest "$TMP/host" "$TMP/current"
	assert_success
	run gpg --verify "$TMP/current/releases.json.sig" "$TMP/current/releases.json"
	assert_success
	[ ! -e "$TMP/current/pool" ]
	run bash "$HOST_SCRIPT" pull-manifest "$TMP/host/beta" "$TMP/beta"
	assert_failure
	assert_output --partial "has no signed releases.json"
}

@test "apt host: the R2 remote is written for the owner only and needs every secret" {
	export RCLONE_CONFIG="$TMP/rclone/rclone.conf"
	APT_R2_ACCESS_KEY_ID=id APT_R2_SECRET_ACCESS_KEY= APT_R2_ENDPOINT=https://example.invalid \
		run bash "$HOST_SCRIPT" configure
	assert_failure
	assert_output --partial "APT_R2_SECRET_ACCESS_KEY is empty"
	APT_R2_ACCESS_KEY_ID=id APT_R2_SECRET_ACCESS_KEY=secret APT_R2_ENDPOINT=https://example.invalid \
		run bash "$HOST_SCRIPT" configure
	assert_success
	[ "$(stat -c %a "$RCLONE_CONFIG")" = 600 ]
	grep -qx 'no_check_bucket = true' "$RCLONE_CONFIG"
	run bash "$HOST_SCRIPT" unconfigure
	assert_success
	[ ! -e "$RCLONE_CONFIG" ]
}
