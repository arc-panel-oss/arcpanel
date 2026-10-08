#!/bin/bash
# shellcheck disable=SC2034

#===========================================================================#
#                                                                           #
# ArcPanel system packages: a whitelist catalog of runtimes and tools that  #
# admins install from the distribution's apt archive (E5)                   #
#                                                                           #
#   $ARCPANEL/install/common/apps/system/<slug>.json  the catalog           #
#   $ARCPANEL/data/sys-packages.conf  one line per slug that ArcPanel       #
#     installed: SLUG, PACKAGES (only those that were missing), TIME, DATE  #
#   $ARCPANEL/data/sys-packages.lock  flock of the writers                  #
# dpkg is the truth for what is installed; the record only says which      #
# packages ArcPanel may remove again.                                       #
# See docs/features/specs/                                                 #
#   2026-10-06-006-e5-e6b2-system-packages-templates-design.md.             #
#                                                                           #
#===========================================================================#

# shellcheck source=/usr/local/arcpanel/func/arc-template.sh
source "$ARCPANEL/func/arc-template.sh"

SP_CATEGORIES="runtime media tooling"
# Runtime ids a package can provide (the runtime of template metadata)
SP_RUNTIMES="python go ruby"
# Releases an "os" override can name (install/arcpanel-install.sh)
SP_RELEASES="debian-12 debian-13 ubuntu-22.04 ubuntu-24.04 ubuntu-26.04"
SP_CONF="$ARCPANEL/data/sys-packages.conf"
SP_LOCK="$ARCPANEL/data/sys-packages.lock"

# Clean environment of every apt call
SP_APT_ENV=(env DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8 NEEDRESTART_MODE=a)
SP_APT_OPTS=(-o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

#----------------------------------------------------------#
#                       Catalog                            #
#----------------------------------------------------------#

sp_catalog_dir() {
	echo "${ARC_SYS_PACKAGES_CATALOG:-$ARCPANEL/install/common/apps/system}"
}

sp_slug_valid() {
	[[ "$1" =~ ^[a-z][a-z0-9-]{1,31}$ ]]
}

sp_manifest() {
	echo "$(sp_catalog_dir)/$1.json"
}

# Slugs of the catalog, sorted; file names that are not slugs are skipped
sp_slugs() {
	local file slug
	for file in "$(sp_catalog_dir)"/*.json; do
		[ -f "$file" ] || continue
		slug=$(basename "$file" .json)
		if sp_slug_valid "$slug"; then
			echo "$slug"
		fi
	done | sort
}

# The jq program that prints one line per problem of a manifest (spec §4.1)
SP_MANIFEST_JQ='
	def str(f): (f | type) == "string";
	def pkgs: type == "array" and length >= 1 and length <= 10
		and all(.[]; type == "string" and test("^[a-z0-9][a-z0-9+.-]+$"));
	if type != "object" then "not a JSON object" else
	(keys - ["schema", "slug", "name", "summary", "category", "packages", "os", "provides", "version_cmd"]
		| .[] | "unknown key \(.)"),
	(if .schema != 1 then "schema must be 1" else empty end),
	(if .slug != $slug then "slug must be \"\($slug)\" (the file name)" else empty end),
	(if str(.name) and (.name | length) >= 1 and (.name | length) <= 40 then empty
		else "name must be a string of 1 to 40 characters" end),
	(if str(.summary) and (.summary | length) >= 1 and (.summary | length) <= 160 then empty
		else "summary must be a string of 1 to 160 characters" end),
	(if str(.category) and (.category as $c | $cats | index($c) != null) then empty
		else "category must be one of \($cats | join(", "))" end),
	(if (.packages | pkgs) then empty else "packages must be 1 to 10 Debian package names" end),
	(if ((.os // {}) | type) != "object" then "os must be an object"
		else (.os // {} | to_entries[]
			| if (.key as $k | $releases | index($k)) == null then "unknown release \(.key) in os"
				elif .value == null or (.value | pkgs) then empty
				else "os.\(.key) must be null or 1 to 10 Debian package names" end) end),
	(if ((.provides // []) | type) == "array"
		and all((.provides // [])[]; . as $r | $runtimes | index($r) != null)
		and ((.provides // []) | length) == ((.provides // []) | unique | length) then empty
		else "provides must list distinct runtimes of \($runtimes | join(", "))" end),
	(if .version_cmd == null or (str(.version_cmd) and (.version_cmd | length) <= 200) then empty
		else "version_cmd must be a string of at most 200 characters" end)
	end'

sp_words_json() {
	printf '%s\n' $1 | jq -R . | jq -cs .
}

# Problems of the manifest FILE of SLUG, one per line; empty when it is valid
sp_manifest_errors() {
	local file="$1" slug="$2"
	if ! jq -e . "$file" > /dev/null 2>&1; then
		echo "not valid JSON"
		return 0
	fi
	jq -r --arg slug "$slug" --argjson cats "$(sp_words_json "$SP_CATEGORIES")" \
		--argjson releases "$(sp_words_json "$SP_RELEASES")" \
		--argjson runtimes "$(sp_words_json "$SP_RUNTIMES")" "$SP_MANIFEST_JQ" "$file"
}

# Exit with E_NOTEXIST unless SLUG is a valid entry of the catalog
sp_check_slug() {
	if ! sp_slug_valid "$1" || [ ! -f "$(sp_manifest "$1")" ]; then
		check_result "$E_NOTEXIST" "system package $1 is not in the catalog"
	fi
	if [ -n "$(sp_manifest_errors "$(sp_manifest "$1")" "$1")" ]; then
		check_result "$E_INVALID" "the catalog entry of $1 is invalid (v-check-sys-packages)"
	fi
}

# This server's release as named in "os": <ID>-<VERSION_ID>
sp_release() {
	if [ -n "$ARC_SYS_PACKAGES_RELEASE" ]; then
		echo "$ARC_SYS_PACKAGES_RELEASE"
		return
	fi
	(
		. /etc/os-release
		echo "$ID-$VERSION_ID"
	)
}

# Packages of SLUG by slug, filled by sp_remember_packages: a list answers
# the status, version and upgrade checks of a slug from one jq run
declare -gA SP_PACKAGES_OF=()

# Read the packages of SLUG once, for the rest of this command
sp_remember_packages() {
	SP_PACKAGES_OF[$1]=$(sp_packages "$1")
}

# Packages of SLUG on this release, one per line; nothing when the release's
# override is null (not available)
sp_packages() {
	if [ -n "${SP_PACKAGES_OF[$1]+set}" ]; then
		if [ -n "${SP_PACKAGES_OF[$1]}" ]; then
			printf '%s\n' "${SP_PACKAGES_OF[$1]}"
		fi
		return 0
	fi
	jq -r --arg r "$(sp_release)" 'if (.os // {}) | has($r) then .os[$r] else .packages end | (. // [])[]' \
		"$(sp_manifest "$1")"
}

sp_available() {
	[ -n "$(sp_packages "$1")" ]
}

sp_provides() {
	jq -r '(.provides // [])[]' "$(sp_manifest "$1")"
}

sp_pkg_installed() {
	[ "$(dpkg-query -W -f='${db:Status-Status}' "$1" 2> /dev/null)" = 'installed' ]
}

# Packages of SLUG on this release that are not installed, one per line
sp_missing() {
	local pkg
	for pkg in $(sp_packages "$1"); do
		if ! sp_pkg_installed "$pkg"; then
			echo "$pkg"
		fi
	done
}

# installed, partial, missing, or unavailable on this release
sp_status() {
	local total missing
	if ! sp_available "$1"; then
		echo unavailable
		return
	fi
	total=$(sp_packages "$1" | wc -l)
	missing=$(sp_missing "$1" | wc -l)
	if [ "$missing" -eq 0 ]; then
		echo installed
	elif [ "$missing" -eq "$total" ]; then
		echo missing
	else
		echo partial
	fi
}

# Version of an installed SLUG: the first line of version_cmd, otherwise the
# package version of its first package
sp_version() {
	local cmd version='' first
	cmd=$(jq -r '.version_cmd // ""' "$(sp_manifest "$1")")
	if [ -n "$cmd" ]; then
		version=$(timeout 10 bash -c "$cmd" 2> /dev/null | head -n1 | tr -cd 'A-Za-z0-9.+~:_-')
	fi
	if [ -z "$version" ]; then
		first=$(sp_packages "$1" | head -n1)
		version=$(dpkg-query -W -f='${Version}' "$first" 2> /dev/null)
	fi
	echo "$version"
}

#----------------------------------------------------------#
#                        Record                            #
#----------------------------------------------------------#

# Packages ArcPanel installed for SLUG (space separated); empty without a record
sp_record_get() {
	if [ -f "$SP_CONF" ]; then
		grep "^SLUG='$1' " "$SP_CONF" | head -n1 | grep -oE "PACKAGES='[^']*'" | cut -d\' -f2
	fi
}

sp_record_exists() {
	[ -f "$SP_CONF" ] && grep -q "^SLUG='$1' " "$SP_CONF"
}

# Write the record of SLUG with the packages PACKAGES (space separated);
# an empty list deletes it. The caller holds the lock.
sp_record_set() {
	local slug="$1" packages="$2" tmp
	touch "$SP_CONF"
	chmod 640 "$SP_CONF"
	tmp="$SP_CONF.$$"
	grep -v "^SLUG='$slug' " "$SP_CONF" > "$tmp"
	if [ -n "$packages" ]; then
		echo "SLUG='$slug' PACKAGES='$packages' TIME='$(date +%T)' DATE='$(date +%F)'" >> "$tmp"
	fi
	chmod 640 "$tmp"
	mv -f "$tmp" "$SP_CONF"
}

# Packages recorded for the other slugs, one per line
sp_record_others() {
	if [ -f "$SP_CONF" ]; then
		grep -v "^SLUG='$1' " "$SP_CONF" | grep -oE "PACKAGES='[^']*'" | cut -d\' -f2 | tr ' ' '\n' | grep .
	fi
	return 0
}

sp_lock() {
	exec {SP_LOCK_FD}> "$SP_LOCK"
	flock -x "$SP_LOCK_FD"
}

#----------------------------------------------------------#
#                       Usage                              #
#----------------------------------------------------------#

# The runtime of every web domain's web and proxy template, one
# "RUNTIME USER/DOMAIN" line per runtime; templates without a runtime are
# left out. Read once and passed to sp_used_by when several slugs are checked.
sp_domain_runtimes() {
	local conf user line domain name kind key file runtime re
	local -A runtime_of=()
	for conf in "$ARCPANEL"/data/users/*/web.conf; do
		[ -f "$conf" ] || continue
		user=$(basename "$(dirname "$conf")")
		while IFS= read -r line; do
			re="^DOMAIN='([^']+)'"
			[[ "$line" =~ $re ]] || continue
			domain=${BASH_REMATCH[1]}
			for kind in web proxy; do
				if [ "$kind" = web ]; then re=" TPL='([^']*)'"; else re=" PROXY='([^']*)'"; fi
				[[ "$line" =~ $re ]] || continue
				name=${BASH_REMATCH[1]}
				[ -n "$name" ] || continue
				key="$kind:$name"
				if [ -z "${runtime_of[$key]+set}" ]; then
					file=$(arc_tpl_meta_file "$kind" "$name")
					runtime=''
					if [ -n "$file" ]; then
						runtime=$(jq -r '.runtime // ""' "$file" 2> /dev/null)
					fi
					runtime_of[$key]=$runtime
				fi
				runtime=${runtime_of[$key]}
				if [ -n "$runtime" ] && [ "$runtime" != none ]; then
					echo "$runtime $user/$domain"
				fi
			done
		done < "$conf"
	done | sort -u
}

# Web domains whose web or proxy template has a runtime that SLUG provides,
# one USER/DOMAIN per line. MAP is the output of sp_domain_runtimes (read
# when it is not given).
sp_used_by() {
	local provides map
	provides=$(sp_provides "$1")
	if [ -z "$provides" ]; then
		return 0
	fi
	if [ "$#" -gt 1 ]; then map=$2; else map=$(sp_domain_runtimes); fi
	awk -v p=" $(echo $provides) " 'index(p, " " $1 " ") { print $2 }' <<< "$map" | sort -u
}

#----------------------------------------------------------#
#                        List                              #
#----------------------------------------------------------#

# Installed packages with a newer version in the local apt lists, one per line
sp_upgradable() {
	apt list --upgradable 2> /dev/null | grep '/' | cut -d/ -f1
}

# One list entry (compact JSON) of SLUG; UPGRADABLE is the output of
# sp_upgradable, MAP the output of sp_domain_runtimes
sp_entry_json() {
	local slug="$1" upgradable="$2" map="$3" status source version='' up=no pkg domains
	status=$(sp_status "$slug")
	if sp_record_exists "$slug"; then
		source=arcpanel
	elif [ "$status" = installed ] || [ "$status" = partial ]; then
		source=system
	else
		source='-'
	fi
	if [ "$status" = installed ] || [ "$status" = partial ]; then
		version=$(sp_version "$slug")
		for pkg in $(sp_packages "$slug"); do
			if grep -qx "$pkg" <<< "$upgradable"; then
				up=yes
			fi
		done
	fi
	domains=$(sp_used_by "$slug" "$map")
	jq -c --arg status "$status" --arg source "$source" --arg version "$version" --arg up "$up" \
		--arg packages "$(sp_packages "$slug")" --arg domains "$domains" '{
			SLUG: .slug, NAME: .name, SUMMARY: .summary, CATEGORY: .category,
			PROVIDES: (.provides // []),
			PACKAGES: ($packages | split("\n") | map(select(length > 0))),
			AVAILABLE: (if $status == "unavailable" then "no" else "yes" end),
			STATUS: (if $status == "unavailable" then "missing" else $status end),
			SOURCE: $source, VERSION: $version, UPGRADABLE: $up,
			DOMAINS: ($domains | split("\n") | map(select(length > 0))),
			USED_BY: ($domains | split("\n") | map(select(length > 0)) | length)
		}' "$(sp_manifest "$slug")"
}

#----------------------------------------------------------#
#                         apt                              #
#----------------------------------------------------------#

# Run apt-get with ARGS; on failure print its last lines (and the hint for a
# package that Ubuntu keeps in universe) and return E_UPDATE
sp_apt() {
	local out rc
	out=$(mktemp)
	"${SP_APT_ENV[@]}" apt-get -y "${SP_APT_OPTS[@]}" "$@" > "$out" 2>&1
	rc=$?
	if [ "$rc" -ne 0 ]; then
		tail -n 20 "$out"
		if grep -q 'Unable to locate package' "$out" && [ "$(
			. /etc/os-release
			echo "$ID"
		)" = 'ubuntu' ]; then
			echo "Hint: the package is in Ubuntu's universe component; enable it (add-apt-repository universe) and try again."
		fi
		rm -f "$out"
		return "$E_UPDATE"
	fi
	rm -f "$out"
	return 0
}
