#!/usr/bin/env bash
# Rebuilds the APT repository from the GitHub Releases (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §5.5 and §5.6).
#
# Usage:
#   rebuild-apt-repo.sh download <dir>
#       the packages (debs-<codename>.tar) and manifests of every release into
#       <dir>/<channel>/<NNNN>-<tag>/<codename>/, numbered in version order (needs gh)
#   rebuild-apt-repo.sh build <dir> <out> <signing-key-id>
#       <out>/<channel> for every channel of <dir>: the packages in version order, so the
#       first published file of a version wins as it did on the host, and the verified
#       manifest with the newest "generated"
#   rebuild-apt-repo.sh compare <repo> <other-repo>
#       pool files and their checksums, index entries and manifest of two repositories;
#       exits 1 when they differ (Release dates and signatures are not compared)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

die() {
	echo "rebuild-apt-repo: $*" >&2
	exit 1
}

cmd_download() {
	local dir="$1" n=0 tag prerelease channel target tar
	mkdir -p "$dir"
	# vX.Y.Z-pre sorts as X.Y.Z~pre, before X.Y.Z, as the package versions do
	while read -r tag prerelease; do
		n=$((n + 1))
		channel=stable
		[ "$prerelease" = true ] && channel=beta
		target="$dir/$channel/$(printf '%04d' "$n")-$tag"
		mkdir -p "$target"
		gh release download "$tag" --pattern 'debs-*.tar' --pattern 'releases.json' --pattern 'releases.json.sig' \
			--dir "$target" || die "cannot download the assets of $tag"
		for tar in "$target"/debs-*.tar; do
			[ -e "$tar" ] || continue
			tar -C "$target" -xf "$tar"
			rm -f "$tar"
		done
		echo "rebuild-apt-repo: $tag ($channel)"
	done < <(gh release list --limit 1000 --json tagName,isPrerelease --jq '.[] | "\(.tagName) \(.isPrerelease)"' \
		| grep -E '^v[0-9]+\.[0-9]+\.[0-9]+' \
		| awk '{v = substr($1, 2); sub(/-/, "~", v); print v, $1, $2}' | sort -V -k1,1 | cut -d' ' -f2-)
}

# The manifest with the newest "generated" among the release folders of a channel
newest_manifest() {
	local channel_dir="$1" file best="" best_generated="" generated
	for file in "$channel_dir"/*/releases.json; do
		[ -e "$file" ] || continue
		generated="$(jq -r '.generated' "$file")"
		if [ -z "$best" ] || [[ "$generated" > "$best_generated" ]]; then
			best="$file"
			best_generated="$generated"
		fi
	done
	echo "$best"
}

cmd_build() {
	local dir="$1" out="$2" key="$3" channel_dir channel manifest
	local -a releases
	for channel_dir in "$dir"/*/; do
		channel="$(basename "$channel_dir")"
		mapfile -t releases < <(find "$channel_dir" -mindepth 1 -maxdepth 1 -type d | sort)
		[ "${#releases[@]}" -gt 0 ] || continue
		bash "$SCRIPT_DIR/build-apt-repo.sh" "$out/$channel" "$key" - "${releases[@]}"
		manifest="$(newest_manifest "$channel_dir")"
		[ -n "$manifest" ] || die "no release of channel $channel has a manifest"
		gpg --batch --verify "$manifest.sig" "$manifest" 2> /dev/null || die "the signature of $manifest does not verify"
		cp "$manifest" "$manifest.sig" "$out/$channel/"
		echo "rebuild-apt-repo: $channel from ${#releases[@]} releases, manifest of $(basename "$(dirname "$manifest")")"
	done
}

pool_list() {
	(cd "$1" && find pool -name '*.deb' -exec sha256sum {} + 2> /dev/null | sort -k2)
}

index_list() {
	local index
	(cd "$1" && for index in dists/*/main/binary-*/Packages; do
		[ -e "$index" ] || continue
		awk -v i="$index" '/^Filename:/ {f = $2} /^SHA256:/ {print i, f, $2}' "$index"
	done | sort)
}

cmd_compare() {
	local a="$1" b="$2" different=0
	[ -d "$a" ] && [ -d "$b" ] || die "both repositories must exist"
	if diff <(pool_list "$a") <(pool_list "$b"); then
		echo "rebuild-apt-repo: same pool ($(pool_list "$a" | wc -l) packages)"
	else
		echo "rebuild-apt-repo: the pools differ"
		different=1
	fi
	if diff <(index_list "$a") <(index_list "$b"); then
		echo "rebuild-apt-repo: same index entries"
	else
		echo "rebuild-apt-repo: the indexes differ"
		different=1
	fi
	if [ -f "$a/releases.json" ] || [ -f "$b/releases.json" ]; then
		if [ -f "$a/releases.json" ] && [ -f "$b/releases.json" ] \
			&& diff <(jq -S . "$a/releases.json") <(jq -S . "$b/releases.json"); then
			echo "rebuild-apt-repo: same manifest"
		else
			echo "rebuild-apt-repo: the manifests differ"
			different=1
		fi
	fi
	return "$different"
}

case "${1:-}" in
	download)
		[ "$#" -eq 2 ] || die "usage: rebuild-apt-repo.sh download <dir>"
		cmd_download "$2"
		;;
	build)
		[ "$#" -eq 4 ] || die "usage: rebuild-apt-repo.sh build <dir> <out> <signing-key-id>"
		cmd_build "$2" "$3" "$4"
		;;
	compare)
		[ "$#" -eq 3 ] || die "usage: rebuild-apt-repo.sh compare <repo> <other-repo>"
		cmd_compare "$2" "$3"
		;;
	*)
		die "usage: rebuild-apt-repo.sh download|build|compare …"
		;;
esac
