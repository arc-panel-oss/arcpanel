#!/usr/bin/env bash
# Build a signed APT repository that keeps every published version (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §5.2).
#
# Usage: build-apt-repo.sh <out-dir> <signing-key-id> <current-repo|-> <debs-dir>...
#   <current-repo>             the published repository (dists/ and pool/), or - for none
#   <debs-dir>/<codename>/*.deb  new packages grouped by distribution codename,
#                              e.g. debs/bookworm/arcpanel_2.2.2-1+debian12_amd64.deb
#
# Every package of <current-repo> stays in the new repository. A new file whose
# package, version and architecture are already there for its codename, or came
# in an earlier <debs-dir>, is skipped: the first file wins (spec D7), because a
# release compiles the engines again with the same version and other checksums.
#
# The indexes are written by apt-ftparchive, not reprepro: reprepro 5.3 (Ubuntu
# 24.04) keeps one version per package and has no Limit option (spec §15). The
# pool layout of reprepro is kept, pool/main/<initial>/<source>/<file>, so the
# file names already published do not move.
#
# Produces <out-dir>/{dists,pool} plus <out-dir>/arcpanel.gpg (public key, ASCII
# armored), which the installer downloads and dearmors.
set -euo pipefail

# Architectures of the packages of one codename folder, "all" left out, so an
# amd64-only release keeps an amd64-only index (E47 D8,
# docs/features/specs/2026-10-20-003-e47-arm64-packages-design.md §5.6)
codename_architectures() {
	local deb arch archs=""
	for deb in "$1"/*.deb; do
		[ -e "$deb" ] || continue
		arch="$(dpkg-deb -f "$deb" Architecture)"
		[ "$arch" = all ] && continue
		archs="$archs $arch"
	done
	archs="$(tr ' ' '\n' <<< "$archs" | sed '/^$/d' | sort -u | tr '\n' ' ')"
	archs="${archs% }"
	if [ -z "$archs" ]; then
		echo "build-apt-repo: no package for $(basename "$1")" >&2
		return 1
	fi
	echo "$archs"
}

# "package version architecture" of a .deb
deb_identity() {
	dpkg-deb -f "$1" Package Version Architecture | awk -F': ' '{printf "%s%s", (NR > 1 ? " " : ""), $2} END {print ""}'
}

# Pool path of a .deb, as reprepro lays it out: pool/main/<initial>/<source>/<file>
pool_path() {
	local source initial
	source="$(dpkg-deb -f "$1" Source | cut -d' ' -f1)"
	[ -n "$source" ] || source="$(dpkg-deb -f "$1" Package)"
	initial="${source:0:1}"
	[[ "$source" == lib?* ]] && initial="${source:0:4}"
	echo "pool/main/$initial/$source/$(basename "$1")"
}

# Pool files of every codename of the repository in $1, as "codename<TAB>path"
current_files() {
	local repo="$1" index codename
	for index in "$repo"/dists/*/main/binary-*/Packages "$repo"/dists/*/main/binary-*/Packages.gz; do
		[ -e "$index" ] || continue
		codename="$(basename "$(dirname "$(dirname "$(dirname "$index")")")")"
		case "$index" in
			*.gz) zcat "$index" ;;
			*) cat "$index" ;;
		esac | awk -v c="$codename" -v r="$repo" '/^Filename: / {print c "\t" r "/" $2}'
	done | sort -u
}

# Puts FILE into the staging folder of CODENAME unless its identity is there already
stage_file() {
	local codename="$1" file="$2" id key existing
	id="$(deb_identity "$file")"
	key="$codename $id"
	existing="${staged[$key]:-}"
	if [ -n "$existing" ]; then
		if ! cmp -s "$existing" "$file"; then
			echo "build-apt-repo: keeping pool file $(basename "$existing") for $codename, new build differs: $file"
		fi
		return 0
	fi
	mkdir -p "$stage/$codename"
	ln -f "$file" "$stage/$codename/" 2> /dev/null || cp -f "$file" "$stage/$codename/"
	staged[$key]="$stage/$codename/$(basename "$file")"
}

# Writes the indexes of CODENAME into OUT and its files into OUT/pool
build_codename() {
	local out="$1" key="$2" codename="$3" architectures="$4" dir="$5" tree file path arch bin release
	tree="$(mktemp -d)"
	for file in "$dir"*.deb; do
		path="$(pool_path "$file")"
		mkdir -p "$out/$(dirname "$path")" "$tree/$(dirname "$path")"
		[ -e "$out/$path" ] || ln -f "$file" "$out/$path" 2> /dev/null || cp -f "$file" "$out/$path"
		ln -f "$out/$path" "$tree/$path" 2> /dev/null || cp -f "$out/$path" "$tree/$path"
	done
	for arch in $architectures; do
		bin="$out/dists/$codename/main/binary-$arch"
		mkdir -p "$bin"
		(cd "$tree" && apt-ftparchive --arch "$arch" packages pool) > "$bin/Packages"
		gzip -9n < "$bin/Packages" > "$bin/Packages.gz"
	done
	rm -rf "$tree"
	release="$(mktemp)"
	apt-ftparchive -o APT::FTPArchive::Release::Origin=ArcPanel -o APT::FTPArchive::Release::Label=ArcPanel -o "APT::FTPArchive::Release::Codename=$codename" -o "APT::FTPArchive::Release::Architectures=$architectures" -o APT::FTPArchive::Release::Components=main -o "APT::FTPArchive::Release::Description=ArcPanel packages for $codename" release "$out/dists/$codename" > "$release"
	mv "$release" "$out/dists/$codename/Release"
	gpg --batch --yes --local-user "$key" --digest-algo SHA512 --clearsign -o "$out/dists/$codename/InRelease" "$out/dists/$codename/Release"
	gpg --batch --yes --local-user "$key" --digest-algo SHA512 --armor --detach-sign -o "$out/dists/$codename/Release.gpg" "$out/dists/$codename/Release"
}

main() {
	local out="$1" key="$2" current="$3" codename file dir architectures
	shift 3
	if [ -z "$key" ]; then
		echo "build-apt-repo: signing key id is empty" >&2
		return 1
	fi
	if [ "$current" != - ] && [ ! -d "$current" ]; then
		echo "build-apt-repo: current repository $current does not exist" >&2
		return 1
	fi
	if [ "$current" != - ] && [ "$(realpath -m "$current")" = "$(realpath -m "$out")" ]; then
		echo "build-apt-repo: the output folder must not be the current repository" >&2
		return 1
	fi

	stage="$(mktemp -d)"
	trap 'rm -rf "$stage"' EXIT
	declare -gA staged=()

	if [ "$current" != - ]; then
		while IFS=$'\t' read -r codename file; do
			if [ ! -f "$file" ]; then
				echo "build-apt-repo: $file is in the index of $codename but not in the pool" >&2
				return 1
			fi
			stage_file "$codename" "$file"
		done < <(current_files "$current")
	fi
	for dir in "$@"; do
		for codename in $(find "$dir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort); do
			for file in $(find "$dir/$codename" -maxdepth 1 -name '*.deb' | sort); do
				stage_file "$codename" "$file"
			done
		done
	done
	if [ "${#staged[@]}" -eq 0 ]; then
		echo "build-apt-repo: no package to publish" >&2
		return 1
	fi

	rm -rf "$out"
	mkdir -p "$out"
	for dir in "$stage"/*/; do
		codename="$(basename "$dir")"
		architectures="$(codename_architectures "$dir")"
		build_codename "$out" "$key" "$codename" "$architectures" "$dir"
	done
	gpg --armor --export "$key" > "$out/arcpanel.gpg"
	echo "build-apt-repo: repository ready in $out, ${#staged[@]} packages, $(du -sh "$out" | cut -f1)"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
	if [ "$#" -lt 3 ]; then
		echo "usage: build-apt-repo.sh <out-dir> <signing-key-id> <current-repo|-> <debs-dir>..." >&2
		exit 1
	fi
	main "$@"
fi
