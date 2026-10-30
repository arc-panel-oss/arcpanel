#!/usr/bin/env bash
# Copies the APT repository between the runner and its bucket (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §5.1). Since
# 2026-10-30 the repository is the Cloudflare R2 bucket arcpanel-apt, served at
# https://apt.arcpanel.org/ (docs/operations/release-signing.md).
#
# Usage: apt-host.sh configure                       the rclone remote "r2" from APT_R2_ACCESS_KEY_ID,
#                                                    APT_R2_SECRET_ACCESS_KEY and APT_R2_ENDPOINT
#        apt-host.sh unconfigure                     removes it again
#        apt-host.sh pull <remote> <dir>             the published dists/, pool/ and manifest into <dir>
#        apt-host.sh pull-manifest <remote> <dir>    releases.json and its signature only
#        apt-host.sh push <dir> <remote>             a repository built by build-apt-repo.sh
#        apt-host.sh push-manifest <dir> <remote>    releases.json and its signature only
#   <remote>  rclone path of the channel, no trailing slash: r2:arcpanel-apt for the stable
#             channel, r2:arcpanel-apt/beta for the beta channel, or a local folder in tests
#
# A push never deletes from pool/: it uploads the pool first, then the indexes that
# name its files, the signed Release files of each codename last, then the manifest,
# the public key and the index page of the channel. Every object gets the Cache-Control
# header that the cache rules of apt.arcpanel.org follow: packages never change, indexes
# and the manifest must be revalidated.
set -euo pipefail

RCLONE_CONF="${RCLONE_CONFIG:-$HOME/.config/rclone/rclone.conf}"

# Pool files keep their name and content once published (build-apt-repo.sh, spec D7)
CACHE_POOL="public, max-age=31536000, immutable"
CACHE_INDEX="no-cache"

# Files compared by checksum, not by time: the runner's copies are new on every run. A
# match does not rewrite the object's modification time.
copy_flags=(--checksum --no-update-modtime)

die() {
	echo "apt-host: $*" >&2
	exit 1
}

# The token is limited to the bucket, so rclone must not check or create the bucket
configure() {
	local name
	for name in APT_R2_ACCESS_KEY_ID APT_R2_SECRET_ACCESS_KEY APT_R2_ENDPOINT; do
		[ -n "${!name:-}" ] || die "$name is empty"
	done
	mkdir -p "$(dirname "$RCLONE_CONF")"
	(
		umask 077
		printf '[r2]\ntype = s3\nprovider = Cloudflare\naccess_key_id = %s\nsecret_access_key = %s\nendpoint = %s\nacl = private\nno_check_bucket = true\n' \
			"$APT_R2_ACCESS_KEY_ID" "$APT_R2_SECRET_ACCESS_KEY" "$APT_R2_ENDPOINT" > "$RCLONE_CONF"
	)
}

unconfigure() {
	rm -f "$RCLONE_CONF"
}

# Succeeds when the channel at $1 has a dists/ folder; an empty channel is not an error,
# a failed listing is (rclone exit 3 is "directory not found")
published() {
	local remote="$1" status=0 err
	err="$(mktemp)"
	rclone lsf --max-depth 1 "$remote/dists" > /dev/null 2> "$err" || status=$?
	case "$status" in
		0) rm -f "$err" ;;
		3)
			rm -f "$err"
			return 1
			;;
		*)
			cat "$err" >&2
			rm -f "$err"
			die "cannot list $remote (rclone exit $status)"
			;;
	esac
}

pull() {
	local remote="$1" dir="$2"
	mkdir -p "$dir"
	if ! published "$remote"; then
		# The first publish of a channel: nothing to keep, so no stale copy may survive either
		rm -rf "$dir/dists" "$dir/pool" "$dir/releases.json" "$dir/releases.json.sig"
		echo "apt-host: nothing published at $remote yet"
		return 0
	fi
	# Only what belongs to this channel: the beta repository under / stays out of the stable copy.
	rclone sync "${copy_flags[@]}" \
		--include '/dists/**' --include '/pool/**' --include '/releases.json' --include '/releases.json.sig' \
		"$remote" "$dir"
	echo "apt-host: pulled $(find "$dir/pool" -name '*.deb' 2> /dev/null | wc -l) packages, $(du -sh "$dir" | cut -f1)"
}

pull_manifest() {
	local remote="$1" dir="$2"
	mkdir -p "$dir"
	# rclone prints the cause of a failure above the message
	rclone copy "${copy_flags[@]}" --include '/releases.json' --include '/releases.json.sig' "$remote" "$dir" \
		|| die "$remote has no signed releases.json"
	[ -f "$dir/releases.json" ] && [ -f "$dir/releases.json.sig" ] || die "$remote has no signed releases.json"
}

push_manifest() {
	local dir="$1" remote="$2"
	[ -f "$dir/releases.json" ] && [ -f "$dir/releases.json.sig" ] || die "$dir has no signed releases.json"
	rclone copy "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_INDEX" \
		--include '/releases.json' --include '/releases.json.sig' "$dir" "$remote"
}

push() {
	local dir="$1" remote="$2"
	[ -d "$dir/pool" ] && [ -d "$dir/dists" ] || die "$dir is not a repository"
	rclone copy "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_POOL" "$dir/pool" "$remote/pool"
	# Excluded files are not deleted by a sync, so the signed Release files change last:
	# Release first, then InRelease and Release.gpg, which sign it.
	rclone sync "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_INDEX" \
		--exclude InRelease --exclude Release --exclude Release.gpg "$dir/dists" "$remote/dists"
	rclone copy "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_INDEX" \
		--include Release "$dir/dists" "$remote/dists"
	rclone sync "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_INDEX" "$dir/dists" "$remote/dists"
	push_manifest "$dir" "$remote"
	rclone copy "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_INDEX" \
		--include '/arcpanel.gpg' "$dir" "$remote"
	push_index "$remote"
	echo "apt-host: pushed $dir to $remote"
}

# A bucket lists no folders, but the installers request the channel root (/ or /beta/) and
# stop unless it answers 200: a URL rewrite rule of the zone serves this page there
push_index() {
	local remote="$1" page
	page="$(mktemp -d)"
	printf '%s\n' '<!doctype html>' '<meta charset="utf-8">' '<title>ArcPanel APT repository</title>' \
		'<p>ArcPanel APT repository. Install ArcPanel with the installer described at <a href="https://arcpanel.org/">arcpanel.org</a>.</p>' \
		> "$page/index.html"
	rclone copy "${copy_flags[@]}" --header-upload "Cache-Control: $CACHE_INDEX" "$page" "$remote"
	rm -rf "$page"
}

case "${1:-}" in
	configure | unconfigure)
		[ "$#" -eq 1 ] || die "usage: apt-host.sh $1"
		"$1"
		exit 0
		;;
	pull | pull-manifest) [ "$#" -eq 3 ] || die "usage: apt-host.sh $1 <remote> <dir>" ;;
	push | push-manifest) [ "$#" -eq 3 ] || die "usage: apt-host.sh $1 <dir> <remote>" ;;
	*) die "usage: apt-host.sh configure|unconfigure|pull|pull-manifest|push|push-manifest …" ;;
esac
command -v rclone > /dev/null || die "rclone is not installed"
"${1//-/_}" "$2" "$3"
