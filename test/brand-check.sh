#!/usr/bin/env bash
# ArcPanel brand check
#
# Fails when user-visible text still says "Hestia" or links to hestiacp.com.
# Lower-case technical identifiers (/usr/local/hestia, HESTIA_*, v-*-hestia-*,
# package internals) are intentionally not checked; see ADR-0006.
#
# Usage: test/brand-check.sh [file ...]
#   Without arguments the whole repository is checked.
#   With arguments only the given files are checked (used by lint-staged).
#
# Exceptions live in test/brand-allowlist.txt, one per line:
#   <path-glob> :: <fixed text>     ignore matching lines in matching files
#   <path-glob> :: *                ignore the whole file
# Lines starting with '#' are comments. Every entry needs a comment saying why.

set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALLOWLIST="$ROOT/test/brand-allowlist.txt"
PATTERN='Hestia|hestiacp\.com'
# In shell code only lines that print or send text to a user are checked.
OUTPUT_PATTERN='echo|printf|v-add-user-notification|subject|message|title='

# Paths where every line is user-visible.
FULL_PATHS=(
	web/templates
	web/locale/hestiacp.pot
	web/inc/mail-wrapper.php
	docs
	README.md
	CONTRIBUTING.md
	SECURITY.md
	.github/ISSUE_TEMPLATE
)

# Paths where only output lines are user-visible.
OUTPUT_PATHS=(
	bin
	func
	install
)

cd "$ROOT" || exit 2

declare -a allow_globs=()
declare -a allow_texts=()
if [[ -f "$ALLOWLIST" ]]; then
	while IFS= read -r entry || [[ -n "$entry" ]]; do
		[[ -z "${entry// /}" || "$entry" == \#* ]] && continue
		if [[ "$entry" != *" :: "* ]]; then
			echo "brand-check: invalid allowlist entry (missing ' :: '): $entry" >&2
			exit 2
		fi
		allow_globs+=("${entry%% :: *}")
		allow_texts+=("${entry#* :: }")
	done < "$ALLOWLIST"
fi

is_allowed() {
	local file="$1" line="$2" i
	for i in "${!allow_globs[@]}"; do
		# shellcheck disable=SC2053 # glob match is intended
		if [[ "$file" == ${allow_globs[$i]} ]]; then
			if [[ "${allow_texts[$i]}" == "*" || "$line" == *"${allow_texts[$i]}"* ]]; then
				return 0
			fi
		fi
	done
	return 1
}

in_paths() {
	local file="$1"
	shift
	local p
	for p in "$@"; do
		[[ "$file" == "$p" || "$file" == "$p"/* ]] && return 0
	done
	return 1
}

GREP_EXCLUDES=(--exclude-dir=node_modules --exclude-dir=.git --exclude='*.mo' --exclude='*.png' --exclude='*.ico')

scan() {
	if [[ $# -gt 0 ]]; then
		local f
		for f in "$@"; do
			f="${f#./}"
			f="${f#"$ROOT"/}"
			[[ -f "$f" ]] || continue
			if in_paths "$f" "${FULL_PATHS[@]}"; then
				grep -HnIE "$PATTERN" "$f"
			elif in_paths "$f" "${OUTPUT_PATHS[@]}"; then
				grep -HnIE "$PATTERN" "$f" | grep -E "^[^:]+:[0-9]+:.*($OUTPUT_PATTERN)"
			fi
		done
	else
		grep -rHnIE "${GREP_EXCLUDES[@]}" "$PATTERN" "${FULL_PATHS[@]}" 2> /dev/null
		grep -rHnIE "${GREP_EXCLUDES[@]}" "$PATTERN" "${OUTPUT_PATHS[@]}" 2> /dev/null \
			| grep -E "^[^:]+:[0-9]+:.*($OUTPUT_PATTERN)"
	fi
}

failures=0
while IFS= read -r hit; do
	[[ -z "$hit" ]] && continue
	file="${hit%%:*}"
	rest="${hit#*:}"
	text="${rest#*:}"
	if ! is_allowed "$file" "$text"; then
		echo "$hit"
		failures=$((failures + 1))
	fi
done < <(scan "$@")

if [[ $failures -gt 0 ]]; then
	echo
	echo "brand-check: $failures user-visible 'Hestia' reference(s) found."
	echo "Replace them with ArcPanel, or add a justified entry to test/brand-allowlist.txt."
	exit 1
fi
echo "brand-check: OK"
