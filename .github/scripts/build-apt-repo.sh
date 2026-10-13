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

rm -rf "$repo"
mkdir -p "$repo/conf"

for dir in "$debs"/*/; do
	codename="$(basename "$dir")"
	cat >> "$repo/conf/distributions" << EOF
Origin: ArcPanel
Label: ArcPanel
Codename: $codename
Architectures: amd64
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
