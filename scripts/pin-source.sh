#!/bin/bash
# Prints the SHA-256 pin of a third-party archive for a version bump (E39 W3,
# docs/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md D14).
# Maintainer tool: nothing runs it at build or install time.
#
# Usage: scripts/pin-source.sh NAME VERSION
#   NAME: php nginx openssl pcre2 zlib (src/sources.sha256)
#         phpmyadmin roundcube snappymail b2 rclone (install/upgrade/upgrade.conf)
#
# Where the upstream publishes a checksum (php.net, OpenSSL, phpMyAdmin,
# Backblaze, rclone), the script compares it and stops on a difference. For
# the others, compare with the signature or the release announcement before
# committing the pin.

set -euo pipefail

name="${1:-}"
v="${2:-}"
if [ -z "$name" ] || [ -z "$v" ]; then
	sed -n '5,7p' "$0" | sed 's/^# //'
	exit 1
fi

published=''
case "$name" in
	php)
		url="https://www.php.net/distributions/php-$v.tar.gz"
		published=$(curl --proto '=https' -fsSL "https://www.php.net/releases/index.php?json&version=$v" \
			| grep -oE "\"filename\":\"php-$v\\.tar\\.gz\"[^}]*\"sha256\":\"[0-9a-f]{64}\"" | grep -oE '[0-9a-f]{64}' || true)
		;;
	nginx) url="https://nginx.org/download/nginx-$v.tar.gz" ;;
	openssl)
		url="https://github.com/openssl/openssl/releases/download/openssl-$v/openssl-$v.tar.gz"
		published=$(curl --proto '=https' -fsSL "$url.sha256" | grep -oE '^[0-9a-f]{64}' || true)
		;;
	pcre2) url="https://github.com/PCRE2Project/pcre2/releases/download/pcre2-$v/pcre2-$v.tar.gz" ;;
	zlib) url="https://github.com/madler/zlib/releases/download/v$v/zlib-$v.tar.gz" ;;
	phpmyadmin)
		url="https://files.phpmyadmin.net/phpMyAdmin/$v/phpMyAdmin-$v-all-languages.tar.gz"
		published=$(curl --proto '=https' -fsSL "$url.sha256" | grep -oE '^[0-9a-f]{64}' || true)
		;;
	roundcube) url="https://github.com/roundcube/roundcubemail/releases/download/$v/roundcubemail-$v-complete.tar.gz" ;;
	snappymail) url="https://github.com/the-djmaze/snappymail/releases/download/v$v/snappymail-$v.tar.gz" ;;
	b2)
		url="https://github.com/Backblaze/B2_Command_Line_Tool/releases/download/v$v/b2-linux"
		published=$(curl --proto '=https' -fsSL "${url}_hashes.txt" | awk 'tolower($1) == "sha256" { print $2 }' || true)
		;;
	rclone)
		url="https://downloads.rclone.org/v$v/rclone-v$v-linux-amd64.zip"
		published=$(curl --proto '=https' -fsSL "https://downloads.rclone.org/v$v/SHA256SUMS" \
			| awk -v f="rclone-v$v-linux-amd64.zip" '$2 == f { print $1 }' || true)
		;;
	*)
		echo "Unknown name: $name" >&2
		exit 1
		;;
esac

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
curl --proto '=https' --tlsv1.2 -fsSL -o "$tmp" "$url"
sum=$(sha256sum "$tmp" | cut -d' ' -f1)

if [ -n "$published" ]; then
	if [ "$published" != "$sum" ]; then
		echo "Error: the download ($sum) differs from the checksum the upstream publishes ($published)" >&2
		exit 1
	fi
	echo "Matches the checksum the upstream publishes."
else
	echo "The upstream publishes no checksum: verify its signature or announcement before committing."
fi

case "$name" in
	php | nginx | openssl | pcre2 | zlib) echo "src/sources.sha256:  $sum  ${url##*/}" ;;
	phpmyadmin) echo "install/upgrade/upgrade.conf:  pma_v='$v'  pma_sha256='$sum'" ;;
	roundcube) echo "install/upgrade/upgrade.conf:  rc_v='$v'  rc_sha256='$sum'" ;;
	snappymail) echo "install/upgrade/upgrade.conf:  sm_v='$v'  sm_sha256='$sum'" ;;
	b2) echo "install/upgrade/upgrade.conf:  b2_v='$v'  b2_sha256='$sum'" ;;
	rclone) echo "install/upgrade/upgrade.conf:  rclone_v='$v'  rclone_sha256='$sum'" ;;
esac
