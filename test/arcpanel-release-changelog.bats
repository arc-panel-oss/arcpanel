#!/usr/bin/env bats
# Changelog fragments across release lines (docs/features/specs/2026-10-21-001-release-standards-design.md §5.5, §10):
# a fix with its fragment merged forward, a release on the newer line, then a patch of the
# older line merged forward; CHANGELOG.md is the only changelog file that conflicts, and
# `changelog build` resolves it. Needs git and Node.js, no server: runs locally and in the
# lint workflow against a throwaway repository with a copy of scripts/release.mjs.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"

release() {
	node scripts/release.mjs "$@"
}

# Writes a fragment of the current line
fragment() {
	printf -- '---\ntype: %s\n---\n\n- %s\n' "$2" "$3" > "changelog/unreleased/$1.md"
}

commit() {
	git add -A
	git commit -q -m "$1"
}

setup() {
	TMP="$(mktemp -d)"
	export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
	export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com
	unset RELEASE_ROOT RELEASE_BRANCH
	git init -q -b v2.2.x "$TMP/work"
	cd "$TMP/work"
	git config core.autocrlf false
	mkdir -p scripts src/deb/arcpanel install changelog/unreleased
	cp "$REPO_ROOT/scripts/release.mjs" scripts/
	printf 'Package: arcpanel\nVersion: 2.2.1~alpha\n' > src/deb/arcpanel/control
	printf "ARCPANEL_INSTALL_VER='2.2.1~alpha'\n" > install/arcpanel-install-debian.sh
	printf "ARCPANEL_INSTALL_VER='2.2.1~alpha'\n" > install/arcpanel-install-ubuntu.sh
	printf '{\n\t"version": "2.2.1",\n\t"security": false,\n\t"attention": false,\n\t"attention_items": [],\n\t"min_from": null\n}\n' > src/release.json
	printf '# Changelog\n\nAll notable changes.\n' > changelog/header.md
	printf '## 2.2.0 — 2026-10-17\n\n### Added\n\n- The 2.2 line.\n' > changelog/2.2.0.md
	touch changelog/unreleased/.gitkeep
	release changelog build > /dev/null
	commit "2.2.0 released"

	# The newer line starts from the older one and has a feature of its own
	git switch -q -c v2.3.x
	release open 2.3.0 > /dev/null
	fragment e40-firewall added "IPv6 in the firewall."
	commit "add the 2.3 feature"
}

teardown() {
	cd /
	rm -rf "$TMP"
}

@test "the lines pass their checks" {
	run release check
	assert_success
	assert_output --partial "2.3.0~alpha passes on v2.3.x"
	git switch -q v2.2.x
	run release check
	assert_success
}

@test "a fix merged forward brings its fragment without a conflict" {
	git switch -q v2.2.x
	fragment e33-fix fixed "A fix of 2.2."
	commit "fix on 2.2"
	git switch -q v2.3.x
	run git merge -q --no-ff -m "Merge branch 'v2.2.x' into v2.3.x" v2.2.x
	assert_success
	assert [ -f changelog/unreleased/e33-fix.md ]
	assert [ -f changelog/unreleased/e40-firewall.md ]
	run release check
	assert_success
	run release changelog preview
	assert_output --partial "- IPv6 in the firewall."
	assert_output --partial "- A fix of 2.2."
}

@test "a patch of the older line after a release of the newer one conflicts only in CHANGELOG.md" {
	# The fix lands on 2.2 and is merged forward
	git switch -q v2.2.x
	fragment e33-fix fixed "A fix of 2.2."
	commit "fix on 2.2"
	git switch -q v2.3.x
	git merge -q --no-ff -m "Merge branch 'v2.2.x' into v2.3.x" v2.2.x

	# 2.3.0 is released with the feature and the fix, then 2.3.1 is opened
	run release cut --date 2026-10-28
	assert_success
	commit "chore(release): set version 2.3.0"
	release open 2.3.1 > /dev/null
	commit "chore(release): open 2.3.1"

	# 2.2.1 is released with the fix, then 2.2.2 is opened
	git switch -q v2.2.x
	run release cut --date 2026-10-29
	assert_success
	commit "chore(release): set version 2.2.1"
	release open 2.2.2 > /dev/null
	commit "chore(release): open 2.2.2"

	# Merge forward: the version files conflict as before, the changelog only in CHANGELOG.md
	git switch -q v2.3.x
	run git merge --no-ff -m "Merge branch 'v2.2.x' into v2.3.x" v2.2.x
	assert_failure
	run git diff --name-only --diff-filter=U
	assert_output --partial "CHANGELOG.md"
	refute_output --partial "changelog/"
	git checkout -q --ours -- src/deb/arcpanel/control install src/release.json
	run release check
	assert_failure
	assert_output --partial "C9 CHANGELOG.md differs from the build"
	release changelog build > /dev/null
	git add -A
	git commit -q --no-edit

	run release check
	assert_success
	assert [ -f changelog/2.2.1.md ]
	assert [ -f changelog/2.3.0.md ]
	run ls changelog/unreleased
	assert_output ""
	run grep '^## ' CHANGELOG.md
	assert_output "$(printf '## 2.3.0 — 2026-10-28\n## 2.2.1 — 2026-10-29\n## 2.2.0 — 2026-10-17')"
	# The fix is in both releases, which is true
	run grep -c 'A fix of 2.2.' CHANGELOG.md
	assert_output "2"
}
