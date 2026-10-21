#!/usr/bin/env bats
# Release branches (docs/features/specs/2026-10-20-004-release-branching-design.md §4.5):
# .github/scripts/release-branches.sh checks that a tag sits on its line branch
# and moves release and master forward after a stable release. Needs no server:
# runs from the repository root against a throwaway origin, locally and in the
# lint workflow.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
SCRIPT="$REPO_ROOT/.github/scripts/release-branches.sh"

# Commits a change of the control version in the work tree
commit_version() {
	printf 'Package: arcpanel\nVersion: %s\n' "$1" > src/deb/arcpanel/control
	git add src/deb/arcpanel/control
	git commit -q -m "set version $1"
}

setup() {
	TMP="$(mktemp -d)"
	export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
	export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com
	git init -q --bare "$TMP/origin.git"
	git init -q -b develop "$TMP/work"
	cd "$TMP/work"
	git remote add origin "$TMP/origin.git"
	mkdir -p src/deb/arcpanel

	# v2.2.1 is the newest stable release; release and master point at it
	commit_version 2.2.1
	git tag -a v2.2.1 -m v2.2.1
	git branch v2.2.x
	git branch master
	git branch release
	# The 2.3 line starts from 2.2.1 and has a stable 2.3.0
	git switch -q -c v2.3.x
	commit_version 2.3.0
	git tag -a v2.3.0 -m v2.3.0
	# A later patch of the older line
	git switch -q v2.2.x
	commit_version 2.2.2
	git tag -a v2.2.2 -m v2.2.2
	# A beta of the 2.3 line
	git switch -q v2.3.x
	commit_version 2.3.1~beta.1
	git tag -a v2.3.1-beta.1 -m v2.3.1-beta.1
	git push -q origin develop v2.2.x v2.3.x master release --tags
	git fetch -q origin
}

teardown() {
	cd /
	rm -rf "$TMP"
}

remote_ref() {
	git --git-dir="$TMP/origin.git" rev-parse "$1"
}

@test "check accepts a stable tag on its line branch" {
	run bash "$SCRIPT" check v2.3.0
	assert_success
}

@test "check accepts a pre-release tag on its line branch" {
	run bash "$SCRIPT" check v2.3.1-beta.1
	assert_success
}

@test "check accepts a release candidate tag on its line branch" {
	git switch -q v2.3.x
	commit_version 2.3.1~rc.1
	git tag -a v2.3.1-rc.1 -m v2.3.1-rc.1
	git push -q origin v2.3.x v2.3.1-rc.1
	git fetch -q origin
	run bash "$SCRIPT" check v2.3.1-rc.1
	assert_success
	assert_output --partial "v2.3.x"
}

@test "check refuses a tag that is not on its line branch" {
	git switch -q -c stray v2.2.1
	commit_version 2.4.0
	git tag -a v2.4.0 -m v2.4.0
	git push -q origin v2.4.0
	git fetch -q origin
	run bash "$SCRIPT" check v2.4.0
	assert_failure
	assert_output --partial "v2.4.x"
}

@test "check refuses a tag on the wrong line" {
	git tag -a v2.3.5 v2.2.2 -m v2.3.5
	git push -q origin v2.3.5
	run bash "$SCRIPT" check v2.3.5
	assert_failure
	assert_output --partial "not on origin/v2.3.x"
}

@test "check refuses a malformed tag" {
	run bash "$SCRIPT" check release-2.3
	assert_failure
}

@test "advance moves release and master to a newer stable tag" {
	run bash "$SCRIPT" advance v2.3.0
	assert_success
	assert_equal "$(remote_ref refs/heads/release)" "$(remote_ref 'v2.3.0^{commit}')"
	assert_equal "$(remote_ref refs/heads/master)" "$(remote_ref 'v2.3.0^{commit}')"
}

@test "advance leaves the branches alone for a patch of an older line" {
	bash "$SCRIPT" advance v2.3.0
	run bash "$SCRIPT" advance v2.2.2
	assert_success
	assert_output --partial "not newer"
	assert_equal "$(remote_ref refs/heads/release)" "$(remote_ref 'v2.3.0^{commit}')"
}

@test "advance does nothing for the version already on release" {
	run bash "$SCRIPT" advance v2.2.1
	assert_success
	assert_output --partial "not newer"
	assert_equal "$(remote_ref refs/heads/release)" "$(remote_ref 'v2.2.1^{commit}')"
}

@test "advance refuses a pre-release tag" {
	run bash "$SCRIPT" advance v2.3.1-beta.1
	assert_failure
	assert_equal "$(remote_ref refs/heads/release)" "$(remote_ref 'v2.2.1^{commit}')"
}

@test "advance fails without force when master cannot fast-forward" {
	git switch -q -c other v2.2.1
	echo x > other.txt
	git add other.txt
	git commit -q -m other
	git push -q origin other:master
	before="$(remote_ref refs/heads/master)"
	run bash "$SCRIPT" advance v2.3.0
	assert_failure
	assert_output --partial "master"
	assert_equal "$(remote_ref refs/heads/master)" "$before"
	assert_equal "$(remote_ref refs/heads/release)" "$(remote_ref 'v2.2.1^{commit}')"
}
