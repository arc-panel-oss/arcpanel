#!/usr/bin/env bash
# Copies the APT repository between the runner and its host (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §5.1).
#
# Usage: apt-host.sh pull <remote> <dir>           the published dists/, pool/ and manifest into <dir>
#        apt-host.sh push <dir> <remote>           a repository built by build-apt-repo.sh
#        apt-host.sh push-manifest <dir> <remote>  releases.json and its signature only
#   <remote>  user@host:/path of the channel (the web root, or its beta/ folder), no trailing slash
#
# A push never deletes from pool/: it uploads the pool first, then the indexes that
# name its files, the signed Release files of each codename last, then the manifest
# and the public key. APT_SSH is the ssh command (default: the deploy key of the
# release workflow).
set -euo pipefail

ssh_cmd="${APT_SSH:-ssh -i $HOME/.ssh/id_deploy -o IdentitiesOnly=yes}"

die() {
	echo "apt-host: $*" >&2
	exit 1
}

pull() {
	local remote="$1" dir="$2"
	mkdir -p "$dir"
	# Only what belongs to this channel: the beta repository under / stays out of the stable copy.
	rsync -az --delete -e "$ssh_cmd" \
		--include '/dists/***' --include '/pool/***' --include '/releases.json' --include '/releases.json.sig' \
		--exclude '*' "$remote/" "$dir/"
	echo "apt-host: pulled $(find "$dir/pool" -name '*.deb' 2> /dev/null | wc -l) packages, $(du -sh "$dir" | cut -f1)"
}

push_manifest() {
	local dir="$1" remote="$2"
	[ -f "$dir/releases.json" ] && [ -f "$dir/releases.json.sig" ] || die "$dir has no signed releases.json"
	rsync -azc -e "$ssh_cmd" "$dir/releases.json" "$dir/releases.json.sig" "$remote/"
}

push() {
	local dir="$1" remote="$2"
	[ -d "$dir/pool" ] && [ -d "$dir/dists" ] || die "$dir is not a repository"
	# From the top, so the channel folder is created on a new host (beta/ for instance)
	rsync -az -e "$ssh_cmd" --include '/pool/***' --exclude '*' "$dir/" "$remote/"
	# Excluded files are not deleted by the first pass, so InRelease changes last. The small
	# index files are compared by checksum: size and time can match after a quick rebuild.
	rsync -azc --delete --exclude InRelease --exclude Release --exclude Release.gpg -e "$ssh_cmd" \
		"$dir/dists/" "$remote/dists/"
	rsync -azc --delete -e "$ssh_cmd" "$dir/dists/" "$remote/dists/"
	push_manifest "$dir" "$remote"
	rsync -azc -e "$ssh_cmd" "$dir/arcpanel.gpg" "$remote/arcpanel.gpg"
	echo "apt-host: pushed $dir to the host"
}

case "${1:-}" in
	pull) [ "$#" -eq 3 ] || die "usage: apt-host.sh pull <remote> <dir>" ;;
	push | push-manifest) [ "$#" -eq 3 ] || die "usage: apt-host.sh $1 <dir> <remote>" ;;
	*) die "usage: apt-host.sh pull|push|push-manifest …" ;;
esac
"${1//-/_}" "$2" "$3"
