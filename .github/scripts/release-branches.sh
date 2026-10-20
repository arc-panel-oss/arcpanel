#!/usr/bin/env bash
# Release lines and the stable branches (docs/features/specs/2026-10-20-004-release-branching-design.md §4.5).
#
# Usage: release-branches.sh check <tag>    the tag must sit on its line branch origin/vX.Y.x
#        release-branches.sh advance <tag>  after a stable release: fast-forward release and
#                                           master to the tag when its version is newer than
#                                           the one on origin/release
#
# Runs in a checkout with the origin branches and tags fetched (fetch-depth 0).
# It never forces a push: a branch that cannot fast-forward fails the run.

set -euo pipefail

remote="${RELEASE_REMOTE:-origin}"

die() {
	echo "release-branches: $*" >&2
	exit 1
}

# Splits vX.Y.Z or vX.Y.Z-pre into version, line and pre-release flag
parse_tag() {
	local tag="$1"
	if [[ ! "$tag" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.]+)?$ ]]; then
		die "tag $tag is not vX.Y.Z or vX.Y.Z-<pre-release>"
	fi
	version="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}"
	line="v${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.x"
	prerelease="${BASH_REMATCH[4]}"
}

tag_commit() {
	git rev-parse --verify --quiet "refs/tags/$1^{commit}" || die "tag $1 does not exist"
}

check() {
	local tag="$1" commit
	parse_tag "$tag"
	commit="$(tag_commit "$tag")"
	git rev-parse --verify --quiet "refs/remotes/$remote/$line" > /dev/null \
		|| die "line branch $remote/$line does not exist for tag $tag"
	git merge-base --is-ancestor "$commit" "refs/remotes/$remote/$line" \
		|| die "tag $tag is not on $remote/$line"
	echo "release-branches: tag $tag is on $remote/$line"
}

advance() {
	local tag="$1" commit current newest branch
	parse_tag "$tag"
	[ -z "$prerelease" ] || die "tag $tag is a pre-release; release and master follow stable releases only"
	commit="$(tag_commit "$tag")"
	current="$(git show "refs/remotes/$remote/release:src/deb/arcpanel/control" | awk '/^Version:/ {print $2}' | tr -d '\r')"
	[ -n "$current" ] || die "no Version in $remote/release:src/deb/arcpanel/control"

	newest="$(printf '%s\n%s\n' "$current" "$version" | sort -V | tail -n 1)"
	if [ "$version" = "$current" ] || [ "$newest" != "$version" ]; then
		echo "release-branches: $version is not newer than $current on $remote/release; nothing to move"
		return 0
	fi

	# Check both before moving either, so a refusal leaves the remote unchanged
	for branch in release master; do
		git merge-base --is-ancestor "refs/remotes/$remote/$branch" "$commit" \
			|| die "$remote/$branch is not an ancestor of $tag; move it by hand (never by force)"
	done
	for branch in release master; do
		git push "$remote" "$commit:refs/heads/$branch"
		echo "release-branches: $remote/$branch moved to $tag"
	done
}

case "${1:-}" in
	check | advance)
		[ -n "${2:-}" ] || die "usage: release-branches.sh check|advance <tag>"
		"$1" "$2"
		;;
	*)
		die "usage: release-branches.sh check|advance <tag>"
		;;
esac
