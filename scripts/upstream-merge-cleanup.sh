#!/bin/bash
# Run after `git merge <upstream release>` (E8 spec §10.1): resolves the
# conflicts on paths ArcPanel retired and lists upstream changes to moved
# paths that git did not carry to their new place. Exits 1 while such changes
# wait to be ported by hand; then run it again and test/upstream-diff.sh.

set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

declare -a move_old=() move_new=() retire=()
while read -r kind first second; do
	case "$kind" in
		move)
			move_old+=("$first")
			move_new+=("$second")
			;;
		retire) retire+=("$first") ;;
	esac
done < <(tr -d '\r' < .arcpanel/retired-paths | grep -v '^#')

# moved_to PATH -> the ArcPanel place of an upstream path, or nothing
moved_to() {
	local i
	for i in "${!move_old[@]}"; do
		if [[ "$1" == "${move_old[$i]}" ]]; then
			echo "${move_new[$i]}"
			return
		elif [[ "${move_old[$i]}" == */ && "$1" == "${move_old[$i]}"* ]]; then
			echo "${move_new[$i]}${1#"${move_old[$i]}"}"
			return
		fi
	done
}

is_retired() {
	local glob
	for glob in "${retire[@]}"; do
		# shellcheck disable=SC2053 # the entry is a glob
		[[ "$1" == $glob ]] && return 0
	done
	return 1
}

unmerged="$(git diff --name-only --diff-filter=U)"
pending=0
# Paths of the merge result under retired globs: unmerged (modify/delete) or
# brought back by the merge.
while IFS= read -r path; do
	is_retired "$path" || continue
	target="$(moved_to "$path")"
	if [ -n "$target" ] && [ ! -e "$target" ] && ! grep -qxF -- "$path" <<< "$unmerged"; then
		# a new upstream file in a moved folder: it simply moves along
		mkdir -p "$(dirname "$target")"
		git mv -- "$path" "$target"
		echo "moved: $path -> $target"
	elif [ -n "$target" ]; then
		echo "port by hand: $path -> $target"
		pending=$((pending + 1))
	else
		git rm -q --cached -- "$path" 2> /dev/null
		rm -f -- "$path"
		echo "dropped: $path"
	fi
done < <({
	echo "$unmerged"
	git ls-files -- web install/deb/filemanager
} | sort -u)

if [ "$pending" -gt 0 ]; then
	echo "$pending upstream change(s) to port; keep their web/ copies until then, git rm them afterwards"
	exit 1
fi
echo "upstream-merge-cleanup: nothing left under retired paths"
