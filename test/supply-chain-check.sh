#!/bin/bash
# Supply-chain guard (E39 W3, docs/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md §9).
# Fails when code downloads or installs something without the checks of
# func/download.sh, or when a workflow runs an action that is not pinned to a
# commit. Runs from the repository root, locally and in the lint workflow;
# no server needed.

cd "$(dirname "$0")/.." || exit 1

problems=0
problem() {
	echo "$1: $2"
	problems=$((problems + 1))
}

# Lines allowed to break a rule, as "path:pattern" with the reason
allowed() {
	case "$1" in
		# Runs as the hosting user and checks the installer's signature itself
		bin/v-add-user-composer:*) return 0 ;;
		# Loopback status pages of the RRD collectors
		*localhost:808* | *127.0.0.1*) return 0 ;;
		# Docker's repository key is checked against its fingerprint in docker_repo_add (E4)
		func/docker.sh:*) return 0 ;;
	esac
	return 1
}

# Strips the CR of a Windows checkout so the copies compare equal
lf() {
	tr -d '\r' < "$1"
}

library_block() {
	lf "$1" | sed -n '/^# >>> func\/download.sh$/,/^# <<< func\/download.sh$/p'
}

#----------------------------------------------------------#
#   1. The installers embed func/download.sh unchanged      #
#----------------------------------------------------------#

reference=$(library_block func/download.sh)
if [ -z "$reference" ]; then
	problem func/download.sh 'no ">>> func/download.sh" block'
fi
for installer in install/arcpanel-install-debian.sh install/arcpanel-install-ubuntu.sh; do
	if [ "$(library_block "$installer")" != "$reference" ]; then
		problem "$installer" 'its copy of func/download.sh differs from the library (copy the block between the markers again)'
	fi
done

#----------------------------------------------------------#
#   2–3. No download piped into a shell, no plain HTTP,     #
#        no archive or key fetched without the library      #
#----------------------------------------------------------#

# grep -rn over the shell code, CR stripped
scan() {
	grep -rnE -e "$1" bin func install src scripts \
		--exclude-dir=pga --exclude-dir=vendor --exclude-dir=node_modules \
		--exclude=download.sh --exclude='*.php' 2> /dev/null | tr -d '\r'
}

# Reports each "path:line:text" of stdin that is not allowed
report() {
	local line
	while IFS= read -r line; do
		allowed "$line" || problem "$(cut -d: -f1,2 <<< "$line")" "$1: $(cut -d: -f3- <<< "$line" | sed 's/^[[:space:]]*//')"
	done
}

report 'download piped into a shell' \
	< <(scan '(curl|wget)[^#|]*\|[[:space:]]*(sudo[[:space:]]+)?(ba)?sh([[:space:]]|$)')
report 'download over plain HTTP' < <(scan '(curl|wget)[^#]*http://')
report 'download over plain HTTP' < <(scan "^[[:space:]]*[A-Z_]+='http://")
report 'unchecked download (use fetch_verified or install_apt_key of func/download.sh)' \
	< <(scan '(curl|wget)' | grep -E '(\.tar\.(gz|xz|bz2)|\.tgz|\.zip|\.gpg|\.asc|\.key|gpgkey|b2lnk|_URL"?|\|[[:space:]]*gpg)')
report 'key from a key server (use install_apt_key)' < <(scan 'recv-keys')
report 'engine source without its pin (use fetch_pinned with src/sources.sha256)' \
	< <(grep -nE 'download_file "?\$\{?(NGINX|OPENSSL|PCRE|ZLIB|PHP)\b' src/arcpanel-build.sh | tr -d '\r' | sed 's|^|src/arcpanel-build.sh:|')

#----------------------------------------------------------#
#   4. Every downloaded third-party version has a SHA-256   #
#----------------------------------------------------------#

conf=install/upgrade/upgrade.conf
for name in pma rc sm b2 rclone; do
	if ! lf "$conf" | grep -qE "^${name}_v='[^']+'"; then
		problem "$conf" "no ${name}_v"
	elif ! lf "$conf" | grep -qE "^${name}_sha256='[0-9a-f]{64}'"; then
		problem "$conf" "${name}_v has no ${name}_sha256 (64 hex characters)"
	fi
done

sums=src/sources.sha256
if [ ! -f "$sums" ]; then
	problem "$sums" 'missing: the build sources are not pinned'
elif lf "$sums" | grep -vE '^[0-9a-f]{64}  [A-Za-z0-9._+-]+$' | grep -q .; then
	problem "$sums" 'a line is not "<sha256>  <file name>"'
fi

#----------------------------------------------------------#
#   5. Workflows: actions pinned to a commit, permissions   #
#----------------------------------------------------------#

for workflow in .github/workflows/*.yml; do
	while IFS= read -r line; do
		problem "$workflow:${line%%:*}" "action not pinned to a commit SHA: $(sed 's/^[[:space:]]*//' <<< "${line#*:}")"
	done < <(lf "$workflow" | grep -nE '^[[:space:]]*(-[[:space:]]+)?uses:' \
		| grep -vE 'uses:[[:space:]]*[^@[:space:]]+@[0-9a-f]{40}([[:space:]]|$)' \
		| grep -vE 'uses:[[:space:]]*\./')
	if ! lf "$workflow" | grep -qE '^permissions:'; then
		problem "$workflow" 'no top-level permissions: block'
	fi
done

if [ "$problems" -gt 0 ]; then
	echo "supply-chain-check: $problems problem(s)"
	exit 1
fi
echo 'supply-chain-check: ok'
