#!/usr/bin/env bash
# Build a signed APT repository from packages grouped by distribution codename.
#
# Usage: build-apt-repo.sh <debs-dir> <repo-dir> <signing-key-id>
#   <debs-dir>/<codename>/*.deb   e.g. debs/bookworm/arcpanel_1.0.0-1+debian12_amd64.deb
#
# Produces <repo-dir>/{dists,pool} plus <repo-dir>/arcpanel.gpg (public key,
# ASCII armored), which the installer downloads and dearmors.

set -euo pipefail

debs="$1"
repo="$2"
key="$3"

if [ -z "$key" ]; then
	echo "build-apt-repo: signing key id is empty" >&2
	exit 1
fi

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

rm -rf "$repo"
mkdir -p "$repo/conf"

for dir in "$debs"/*/; do
	codename="$(basename "$dir")"
	architectures="$(codename_architectures "$dir")"
	cat >> "$repo/conf/distributions" << EOF
Origin: ArcPanel
Label: ArcPanel
Codename: $codename
Architectures: $architectures
Components: main
Description: ArcPanel packages for $codename
SignWith: $key

EOF
done

for dir in "$debs"/*/; do
	codename="$(basename "$dir")"
	# The same core package can be uploaded by several jobs; include each file once.
	for deb in $(ls "$dir"*.deb | sort -u); do
		reprepro -b "$repo" --ignore=wrongdistribution includedeb "$codename" "$deb"
	done
done

rm -rf "$repo/conf" "$repo/db"
gpg --armor --export "$key" > "$repo/arcpanel.gpg"
echo "build-apt-repo: repository ready in $repo"
