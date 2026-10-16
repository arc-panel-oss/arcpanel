#!/bin/bash
# shellcheck disable=SC2034 # APT_KEY_FPR_* are read through ${!var}

#===========================================================================#
#                                                                           #
# ArcPanel checked downloads (E39 W3)                                       #
#                                                                           #
# Everything ArcPanel installs from outside its own packages passes one    #
# of two checks:                                                            #
#   fetch_verified / fetch_pinned  HTTPS only, SHA-256 pinned beside the    #
#                                  version, the file lands only on a match  #
#   install_apt_key                every primary key must have a pinned     #
#                                  full fingerprint before the keyring is   #
#                                  written                                  #
# The installers embed this file between the >>> and <<< markers;           #
# test/supply-chain-check.sh fails when a copy differs from it.             #
# See docs/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md §4   #
#                                                                           #
#===========================================================================#

# >>> func/download.sh

# Full fingerprints of the primary keys each APT repository may be signed with,
# read by install_apt_key through APT_KEY_FPR_<NAME>
APT_KEY_FPR_ARCPANEL='0615B3DD8398E546A060B6F74DE318A606E4002A'
APT_KEY_FPR_NGINX='573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62 8540A6F18833A80E9C1653A42FD21310B49F6B46 9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3'
APT_KEY_FPR_SURY='15058500A0235D97F5D10063B188E2B695BD4743'
APT_KEY_FPR_MARIADB='177F4010FE56CA3336300305F1656F24C74CD1D8'
APT_KEY_FPR_POSTGRESQL='B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8'
APT_KEY_FPR_NODESOURCE='6F71F525282841EEDAF851B42F59B5F99B1BE0B4'
APT_KEY_FPR_MYSQL='BCA43417C3B485DD128EC6D4B7B3B788A8D3785C'

# Downloads URL to FILE over HTTPS only
download_https() {
	curl --proto '=https' --tlsv1.2 -fsSL --retry 3 --connect-timeout 20 -o "$2" -- "$1"
}

# Downloads URL to DEST and keeps it only when its SHA-256 is SHA256
# Usage: fetch_verified URL SHA256 DEST
fetch_verified() {
	local url="$1" sum="$2" dest="$3" name tmp actual
	name="${url##*/}"
	if ! [[ "$sum" =~ ^[0-9a-f]{64}$ ]]; then
		echo "Error: no checksum pinned for $name" >&2
		return 1
	fi
	if ! tmp=$(mktemp "$dest.XXXXXX"); then
		echo "Error: cannot write $dest" >&2
		return 1
	fi
	if ! download_https "$url" "$tmp"; then
		rm -f -- "$tmp"
		echo "Error: download of $name failed ($url)" >&2
		return 1
	fi
	actual=$(sha256sum "$tmp" | cut -d' ' -f1)
	if [ "$actual" != "$sum" ]; then
		rm -f -- "$tmp"
		echo "Error: checksum mismatch for $name (expected $sum, got $actual)" >&2
		return 1
	fi
	chmod 0644 "$tmp"
	mv -f -- "$tmp" "$dest"
}

# Like fetch_verified, with the checksum looked up by file name in SUMFILE
# (sha256sum format). A DEST that already matches is kept without a download.
# Usage: fetch_pinned URL SUMFILE DEST
fetch_pinned() {
	local url="$1" sumfile="$2" dest="$3" name sum
	name="${url##*/}"
	sum=$(awk -v f="$name" '$2 == f || $2 == "*" f { print $1; exit }' "$sumfile" 2> /dev/null)
	if [ -z "$sum" ]; then
		echo "Error: no checksum pinned for $name in ${sumfile##*/}" >&2
		return 1
	fi
	if [ -f "$dest" ] && [ "$(sha256sum "$dest" | cut -d' ' -f1)" = "$sum" ]; then
		return 0
	fi
	fetch_verified "$url" "$sum" "$dest"
}

# Downloads the key of repository NAME and writes it to KEYRING only when every
# primary key in it has a fingerprint listed in APT_KEY_FPR_<NAME>
# Usage: install_apt_key NAME URL KEYRING
install_apt_key() {
	local name="$1" url="$2" keyring="$3" var allowed tmp fprs fpr ok
	var="APT_KEY_FPR_$(echo "$name" | tr '[:lower:]-' '[:upper:]_')"
	allowed="${!var}"
	if [ -z "$allowed" ]; then
		echo "Error: no fingerprint pinned for the $name key" >&2
		return 1
	fi
	if ! tmp=$(mktemp -d); then
		return 1
	fi
	if ! download_https "$url" "$tmp/key"; then
		rm -rf -- "$tmp"
		echo "Error: download of the $name key failed ($url)" >&2
		return 1
	fi
	fprs=$(GNUPGHOME="$tmp" gpg --batch --show-keys --with-colons "$tmp/key" 2> /dev/null \
		| awk -F: '$1 == "pub" { pub = 1; next } pub && $1 == "fpr" { print $10; pub = 0 }')
	ok='yes'
	[ -n "$fprs" ] || ok='no'
	for fpr in $fprs; do
		[[ " $allowed " == *" $fpr "* ]] || ok='no'
	done
	if [ "$ok" != 'yes' ]; then
		rm -rf -- "$tmp"
		echo "Error: the $name key does not match its pinned fingerprint (got: ${fprs:-none})" >&2
		return 1
	fi
	if grep -q -- '-----BEGIN PGP PUBLIC KEY BLOCK-----' "$tmp/key"; then
		GNUPGHOME="$tmp" gpg --batch --yes --dearmor -o "$tmp/keyring" "$tmp/key" 2> /dev/null
	else
		cp -f -- "$tmp/key" "$tmp/keyring"
	fi
	if [ ! -s "$tmp/keyring" ] || ! mkdir -p -- "$(dirname "$keyring")"; then
		rm -rf -- "$tmp"
		echo "Error: cannot write the $name keyring" >&2
		return 1
	fi
	chmod 0644 "$tmp/keyring"
	mv -f -- "$tmp/keyring" "$keyring"
	rm -rf -- "$tmp"
}

# <<< func/download.sh
