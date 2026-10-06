#!/bin/bash
# Upstream-diff check (docs/design/11-testing-and-ci.md §5, E8 spec §10.2).
#
# 1. Every upstream file under bin/, func/, install/ and src/ that differs from
#    the imported HestiaCP tree (modified, deleted or renamed) must be listed
#    in test/upstream-exceptions.txt. New files pass: ArcPanel changes are
#    additive unless a spec records an exception (docs/design/01 §3.2).
# 2. No code may reference the web/ directory of the 1.x interface, which is
#    gone since ArcPanel 2.0 (E8).
#
# The imported tree is the commit in test/upstream-base.txt (update it on
# every upstream merge); UPSTREAM_BASE overrides it. CI needs the full
# history (actions/checkout with fetch-depth: 0).

set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

EXCEPTIONS="test/upstream-exceptions.txt"
BASE="${UPSTREAM_BASE:-$(grep -v '^#' test/upstream-base.txt | grep -m1 .)}"

if ! git cat-file -e "${BASE}^{commit}" 2> /dev/null; then
	echo "upstream-diff: base commit '$BASE' not found (shallow clone?)" >&2
	exit 2
fi

declare -a globs=()
while IFS= read -r line || [[ -n "$line" ]]; do
	[[ -z "${line// /}" || "$line" == \#* ]] && continue
	globs+=("$line")
done < "$EXCEPTIONS"

listed() {
	local glob
	for glob in "${globs[@]}"; do
		# shellcheck disable=SC2053 # the entry is a glob
		[[ "$1" == $glob ]] && return 0
	done
	return 1
}

failures=0

while IFS=$'\t' read -r status path _; do
	[[ "$status" == A* ]] && continue
	if ! listed "$path"; then
		echo "upstream-diff: $path changed upstream code ($status) but is not in $EXCEPTIONS"
		failures=$((failures + 1))
	fi
done < <(git -c core.quotePath=false diff --name-status -M "$BASE" -- bin func install src)

# The 1.x web/ directory. Ignored: upstream upgrade steps for versions up to
# 1.10.5, source references of the gettext catalogues, the flag check of
# v-restart-proxy (a file nothing creates), and the clean-up of 1.x leftovers.
old_paths="$(git grep -nE '(\$HESTIA|\$\{HESTIA\}|/usr/local/hestia)/web/|\.\./web/' -- \
	bin func install src lib web-app/app web-app/config web-app/routes web-app/resources test \
	':!install/upgrade/versions/' \
	':!lib/locale/' \
	':!test/brand-allowlist.txt' \
	':!bin/v-restart-proxy' \
	':!bin/v-delete-sys-filemanager' \
	':!install/upgrade/arcpanel/2.0.0.sh' \
	':!test/upstream-diff.sh' \
	':!test/arcpanel-e8.bats')"
if [ -n "$old_paths" ]; then
	echo "upstream-diff: references to the removed web/ directory:"
	echo "$old_paths"
	failures=$((failures + 1))
fi

if [ "$failures" -gt 0 ]; then
	echo "upstream-diff: $failures problem(s); record a needed exception in $EXCEPTIONS and docs/design/01 §3.2"
	exit 1
fi
echo "upstream-diff: OK (base $BASE)"
