#!/usr/bin/env bats
# The release manifest of the APT repository (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §4 and §5.4):
# .github/scripts/release-manifest.sh with generated packages. Needs no server:
# runs from the repository root, locally and in the lint workflow, and skips
# without jq or dpkg-deb.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REAL_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
MANIFEST="$REAL_ROOT/.github/scripts/release-manifest.sh"

setup() {
	for tool in jq dpkg-deb; do
		command -v "$tool" > /dev/null || skip "$tool is not installed"
	done
	TMP="$(mktemp -d)"
	# A repository root with the real seed and the upgrade steps 2.2.1 and 2.2.2
	export REPO_ROOT="$TMP/root"
	mkdir -p "$REPO_ROOT/.github" "$REPO_ROOT/install/upgrade/versions"
	cp "$REAL_ROOT/.github/release-manifest-seed.json" "$REPO_ROOT/.github/"
	touch "$REPO_ROOT/install/upgrade/versions/2.2.1.sh" "$REPO_ROOT/install/upgrade/versions/2.2.2.sh"
	export MANIFEST_NOW=2026-10-23T10:00:00Z
}

teardown() {
	[ -z "${TMP:-}" ] || rm -rf "$TMP"
}

# Writes release metadata VERSION [attention] [items JSON] into FILE
metadata() {
	local file="$1" version="$2" attention="${3:-false}" items="${4:-[]}"
	printf '{"version": "%s", "security": false, "attention": %s, "attention_items": %s, "min_from": null}\n' \
		"$version" "$attention" "$items" > "$file"
}

# Builds the four packages of a release with SUFFIX into DIR/<codename>
release_debs() {
	local dir="$1" codename="$2" suffix="$3" package version root
	for package in arcpanel:2.2.2-1 arcpanel-nginx:1.30.5-3-1 arcpanel-php:8.5.11-1-1 arcpanel-web-terminal:1.0.5-1-1; do
		version="${package#*:}+$suffix"
		package="${package%%:*}"
		root="$(mktemp -d "$TMP/pkg.XXXXXX")"
		mkdir -p "$root/DEBIAN" "$dir/$codename"
		printf 'Package: %s\nVersion: %s\nArchitecture: amd64\nMaintainer: Test <test@example.invalid>\nDescription: test\n' \
			"$package" "$version" > "$root/DEBIAN/control"
		dpkg-deb --build --root-owner-group "$root" "$dir/$codename/${package}_${version}_amd64.deb" > /dev/null
	done
}

@test "manifest: the metadata of the line describes its next version" {
	control="$(awk '/^Version:/ {print $2}' "$REAL_ROOT/src/deb/arcpanel/control" | tr -d '\r')"
	run bash "$MANIFEST" check "$REAL_ROOT/src/release.json" "${control%~alpha}"
	assert_success
}

@test "manifest: the seed lists 2.2.1 for stable and nothing for beta" {
	run jq -r '[.stable[].version, (.beta | length)] | join(" ")' "$REAL_ROOT/.github/release-manifest-seed.json"
	assert_output "2.2.1 0"
}

@test "manifest: check refuses metadata of another version" {
	metadata "$TMP/release.json" 2.2.2
	run bash "$MANIFEST" check "$TMP/release.json" 2.2.3
	assert_failure
	assert_output --partial "describes 2.2.2, the package version is 2.2.3"
}

@test "manifest: check refuses an attention release without items" {
	metadata "$TMP/release.json" 2.2.2 true
	run bash "$MANIFEST" check "$TMP/release.json" 2.2.2
	assert_failure
	assert_output --partial "is not valid release metadata"
}

@test "manifest: check refuses a malformed version" {
	metadata "$TMP/release.json" 2.2
	run bash "$MANIFEST" check "$TMP/release.json" 2.2
	assert_failure
	assert_output --partial "is not valid release metadata"
}

@test "manifest: the first release starts from the seed and adds its entry" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	run bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable 2026-10-23
	assert_success
	echo "$output" > "$TMP/m.json"
	run jq -r '[.format, .channel, .generated, (.releases | map(.version) | join(","))] | join(" ")' "$TMP/m.json"
	assert_output "1 stable 2026-10-23T10:00:00Z 2.2.2,2.2.1"
	run jq -c '.releases[0] | {line, date, steps, engines, notes, withdrawn}' "$TMP/m.json"
	assert_output '{"line":"2.2","date":"2026-10-23","steps":true,"engines":{"arcpanel-nginx":"1.30.5-3-1","arcpanel-php":"8.5.11-1-1","arcpanel-web-terminal":"1.0.5-1-1"},"notes":"https://arcpanel.org/changelog#2.2.2","withdrawn":null}'
}

@test "manifest: a release without an upgrade step has no steps" {
	metadata "$TMP/release.json" 2.2.3
	release_debs "$TMP/debs" noble ubuntu24.04
	run bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable
	assert_success
	run jq -r '.releases[0] | "\(.version) \(.steps) \(.date)"' <<< "$output"
	assert_output "2.2.3 false 2026-10-23"
}

@test "manifest: publishing a version again replaces its entry" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	MANIFEST_NOW=2026-10-23T10:00:00Z bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable > "$TMP/m1.json"
	metadata "$TMP/release.json" 2.2.2 true '["The firewall moves to nftables."]'
	run env MANIFEST_NOW=2026-10-23T11:00:00Z bash "$MANIFEST" add "$TMP/m1.json" "$TMP/release.json" "$TMP/debs" stable
	assert_success
	run jq -c '[.releases[] | select(.version == "2.2.2") | .attention_items]' <<< "$output"
	assert_output '[["The firewall moves to nftables."]]'
}

@test "manifest: releases are sorted newest first, pre-releases before their release" {
	release_debs "$TMP/debs" noble ubuntu24.04
	echo '{"format": 1, "channel": "beta", "generated": "2026-10-01T00:00:00Z", "releases": []}' > "$TMP/m0.json"
	prev="$TMP/m0.json"
	i=1
	for version in 2.3.0~beta.2 2.2.10 2.3.0 2.2.9 2.3.0~beta.1; do
		metadata "$TMP/release.json" "$version"
		MANIFEST_NOW="2026-10-2${i}T00:00:00Z" bash "$MANIFEST" add "$prev" "$TMP/release.json" "$TMP/debs" beta > "$TMP/m$i.json"
		prev="$TMP/m$i.json"
		i=$((i + 1))
	done
	run jq -r '.releases | map(.version) | join(" ")' "$prev"
	assert_output "2.3.0 2.3.0~beta.2 2.3.0~beta.1 2.2.10 2.2.9"
}

@test "manifest: generated only moves forward" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable > "$TMP/m1.json"
	run bash "$MANIFEST" add "$TMP/m1.json" "$TMP/release.json" "$TMP/debs" stable
	assert_failure
	assert_output --partial "is not after 2026-10-23T10:00:00Z"
}

@test "manifest: a manifest of another channel is refused" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable > "$TMP/m1.json"
	run env MANIFEST_NOW=2026-10-24T00:00:00Z bash "$MANIFEST" add "$TMP/m1.json" "$TMP/release.json" "$TMP/debs" beta
	assert_failure
	assert_output --partial "is not a format 1 manifest of channel beta"
}

@test "manifest: a release without an engine package is refused" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	rm "$TMP/debs/noble/"arcpanel-php_*.deb
	run bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable
	assert_failure
	assert_output --partial "no arcpanel-php package"
}

@test "manifest: withdraw marks one version and moves generated" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable > "$TMP/m1.json"
	run env MANIFEST_NOW=2026-10-24T08:00:00Z bash "$MANIFEST" withdraw "$TMP/m1.json" 2.2.2 "Breaks the panel NGINX on Debian 13"
	assert_success
	echo "$output" > "$TMP/m2.json"
	run jq -c '[.generated, (.releases[] | {version, withdrawn})]' "$TMP/m2.json"
	assert_output '["2026-10-24T08:00:00Z",{"version":"2.2.2","withdrawn":{"date":"2026-10-24","reason":"Breaks the panel NGINX on Debian 13"}},{"version":"2.2.1","withdrawn":null}]'
}

@test "manifest: withdraw refuses an unknown version and a missing or multi-line reason" {
	metadata "$TMP/release.json" 2.2.2
	release_debs "$TMP/debs" noble ubuntu24.04
	bash "$MANIFEST" add - "$TMP/release.json" "$TMP/debs" stable > "$TMP/m1.json"
	export MANIFEST_NOW=2026-10-24T08:00:00Z
	run bash "$MANIFEST" withdraw "$TMP/m1.json" 2.2.9 "reason"
	assert_failure
	assert_output --partial "version 2.2.9 is not in the manifest"
	run bash "$MANIFEST" withdraw "$TMP/m1.json" 2.2.2 ""
	assert_failure
	assert_output --partial "needs a reason"
	run bash "$MANIFEST" withdraw "$TMP/m1.json" 2.2.2 $'two\nlines'
	assert_failure
	assert_output --partial "must be one line"
}
