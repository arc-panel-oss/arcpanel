#!/usr/bin/env bash
# The signed release manifest of the APT repository (E33 phase 1 P0,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §4 and §5.4).
#
# Usage:
#   release-manifest.sh check <release.json> <package-version>
#       src/release.json must describe the version of src/deb/arcpanel/control
#   release-manifest.sh add <manifest|-> <release.json> <debs-dir> <channel> [date]
#       prints the manifest with the entry of this release added or replaced; without a
#       manifest it starts from the entries of .github/release-manifest-seed.json
#   release-manifest.sh withdraw <manifest> <version> <reason> [date]
#       prints the manifest with <version> marked withdrawn
#
# MANIFEST_NOW (UTC, 2026-10-20T10:00:00Z) replaces the clock for "generated", which only
# moves forward. Needs jq and, for add, dpkg-deb.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
ENGINES=(arcpanel-nginx arcpanel-php arcpanel-web-terminal)
VERSION_RE='^[0-9]+\.[0-9]+\.[0-9]+(~[0-9A-Za-z.]+)?$'

die() {
	echo "release-manifest: $*" >&2
	exit 1
}

now() {
	echo "${MANIFEST_NOW:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
}

# Checks the fields of a release metadata file
check_metadata() {
	local file="$1"
	[ -f "$file" ] || die "$file does not exist"
	jq -e --arg re "$VERSION_RE" '
		(.version | type == "string" and test($re))
		and (.security | type == "boolean")
		and (.attention | type == "boolean")
		and (.attention_items | type == "array" and all(type == "string" and length > 0))
		and (.attention == false or (.attention_items | length > 0))
		and (.min_from == null or (.min_from | type == "string" and test($re)))
	' "$file" > /dev/null || die "$file is not valid release metadata (version, security, attention, attention_items, min_from)"
}

cmd_check() {
	local file="$1" version="$2" declared
	check_metadata "$file"
	declared="$(jq -r .version "$file")"
	[ "$declared" = "$version" ] || die "$file describes $declared, the package version is $version"
	echo "release-manifest: $file describes $version"
}

# The releases a manifest starts with, from its file or the seed of the channel
current_releases() {
	local manifest="$1" channel="$2"
	if [ "$manifest" = - ]; then
		jq -e --arg c "$channel" '.[$c] // empty' "$REPO_ROOT/.github/release-manifest-seed.json" \
			|| die "the seed has no channel $channel"
		return
	fi
	jq -e --arg c "$channel" 'select(.format == 1 and .channel == $c) | .releases' "$manifest" \
		|| die "$manifest is not a format 1 manifest of channel $channel"
}

# Prints the manifest of CHANNEL with RELEASES (a JSON array) sorted newest first
write_manifest() {
	local channel="$1" releases="$2" previous="$3" generated order
	generated="$(now)"
	if [ -n "$previous" ] && [[ ! "$generated" > "$previous" ]]; then
		die "generated $generated is not after $previous"
	fi
	order="$(jq -r '.[].version' <<< "$releases" | sort -V -r | jq -R . | jq -s .)"
	jq -n --arg c "$channel" --arg g "$generated" --argjson r "$releases" --argjson o "$order" '{
		format: 1,
		channel: $c,
		generated: $g,
		releases: [$o[] as $v | $r[] | select(.version == $v)]
	}'
}

previous_generated() {
	[ "$1" = - ] || jq -r '.generated // ""' "$1"
}

# Version of PACKAGE among the .deb files of DIR, without the codename suffix
engine_version() {
	local dir="$1" package="$2" deb
	deb="$(find "$dir" -name "${package}_*.deb" | sort | head -n 1)"
	[ -n "$deb" ] || die "no $package package in $dir"
	dpkg-deb -f "$deb" Version | sed 's/+[^+]*$//'
}

cmd_add() {
	local manifest="$1" metadata="$2" debs="$3" channel="$4" date="${5:-}" version base engines entry releases package engine
	check_metadata "$metadata"
	[[ "$channel" =~ ^(stable|beta)$ ]] || die "channel must be stable or beta"
	[ -d "$debs" ] || die "$debs does not exist"
	version="$(jq -r .version "$metadata")"
	base="${version%%~*}"
	[ -n "$date" ] || date="$(now | cut -c1-10)"

	engines='{}'
	for package in "${ENGINES[@]}"; do
		engine="$(engine_version "$debs" "$package")"
		engines="$(jq --arg p "$package" --arg v "$engine" '. + {($p): $v}' <<< "$engines")"
	done

	entry="$(jq --arg date "$date" --arg base "$base" --argjson engines "$engines" \
		--argjson steps "$([ -f "$REPO_ROOT/install/upgrade/versions/$base.sh" ] && echo true || echo false)" '{
		version,
		line: ($base | split(".")[0:2] | join(".")),
		date: $date,
		security,
		attention,
		attention_items,
		steps: $steps,
		min_from,
		engines: $engines,
		notes: ("https://arcpanel.org/changelog#" + .version),
		withdrawn: null
	}' "$metadata")"

	releases="$(current_releases "$manifest" "$channel" | jq --argjson e "$entry" '[.[] | select(.version != $e.version)] + [$e]')"
	write_manifest "$channel" "$releases" "$(previous_generated "$manifest")"
}

cmd_withdraw() {
	local manifest="$1" version="$2" reason="$3" date="${4:-}" channel releases
	[ -f "$manifest" ] || die "$manifest does not exist"
	[ -n "$reason" ] || die "a withdrawal needs a reason"
	[ "${#reason}" -le 200 ] || die "the reason is longer than 200 characters"
	[[ "$reason" != *$'\n'* ]] || die "the reason must be one line"
	channel="$(jq -r .channel "$manifest")"
	releases="$(current_releases "$manifest" "$channel")"
	jq -e --arg v "$version" 'any(.[]; .version == $v)' <<< "$releases" > /dev/null \
		|| die "version $version is not in the manifest"
	[ -n "$date" ] || date="$(now | cut -c1-10)"
	releases="$(jq --arg v "$version" --arg d "$date" --arg r "$reason" \
		'map(if .version == $v then .withdrawn = {date: $d, reason: $r} else . end)' <<< "$releases")"
	write_manifest "$channel" "$releases" "$(previous_generated "$manifest")"
}

case "${1:-}" in
	check)
		[ "$#" -eq 3 ] || die "usage: release-manifest.sh check <release.json> <package-version>"
		cmd_check "$2" "$3"
		;;
	add)
		[ "$#" -ge 5 ] || die "usage: release-manifest.sh add <manifest|-> <release.json> <debs-dir> <channel> [date]"
		cmd_add "$2" "$3" "$4" "$5" "${6:-}"
		;;
	withdraw)
		[ "$#" -ge 4 ] || die "usage: release-manifest.sh withdraw <manifest> <version> <reason> [date]"
		cmd_withdraw "$2" "$3" "$4" "${5:-}"
		;;
	*)
		die "usage: release-manifest.sh check|add|withdraw …"
		;;
esac
