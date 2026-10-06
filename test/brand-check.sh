#!/usr/bin/env bash
# ArcPanel brand check (ADR-0007, full rename spec §8.1)
#
# Fails on any HestiaCP name ("hestia" in any case, "hst_", "hst-") in the
# tracked text files that scripts/rename/keep.txt does not allow, and on
# doubled names such as "arcpanelcp" that an ordered rename would produce.
# keep.txt holds the attribution, the upstream links, the names owned by
# third parties and the old backup formats, each with its reason.
#
# Usage: test/brand-check.sh [file ...]
#   Without arguments the whole repository is checked.
#   With arguments only the given files are checked (used by lint-staged).

set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

out="$(bash "$ROOT/scripts/rename/apply.sh" --check "$@")"
status=$?
if [ "$status" -ne 0 ]; then
	[ -n "$out" ] && echo "$out"
	if [ "$status" -eq 1 ]; then
		echo "brand-check: $(printf '%s\n' "$out" | grep -c .) line(s) with an old name."
		echo "Rename them, or add a justified entry to scripts/rename/keep.txt."
	fi
	exit 1
fi
echo "brand-check: OK"
