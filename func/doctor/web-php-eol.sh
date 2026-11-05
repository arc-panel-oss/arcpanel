#!/bin/bash
# E17 check web.php-eol (spec §4.2): the PHP version of the domain gets
# only security fixes (warn) or none at all (fail), from the version catalog
# of v-list-sys-php-versions.
# shellcheck disable=SC2034
DOCTOR_ID=web.php-eol
DOCTOR_SCOPE=web
DOCTOR_TITLE='The PHP version is supported'
DOCTOR_DOCS='/docs/guides/doctor#web-php-eol'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local user="$1" domain="$2" out entry version status eol
	if ! out=$(doctor_cached php-versions "$BIN/v-list-sys-php-versions" json 2> /dev/null) \
		|| ! jq -e 'type == "object"' <<< "$out" > /dev/null 2>&1; then
		dr_skip 'The PHP versions could not be listed.'
		return
	fi
	entry=$(jq -r --arg d "$user/$domain" 'to_entries[]
		| select((.value.USED_BY // "") | split(",") | index($d))
		| [.key, (.value.STATUS // "unknown"), (.value.EOL // "")] | @tsv' <<< "$out" | head -n1)
	if [ -z "$entry" ]; then
		dr_skip 'The domain does not use PHP.'
		return
	fi
	IFS=$'\t' read -r version status eol <<< "$entry"
	DR_LINK=php
	case "$status" in
		eol) dr_fail "PHP $version gets no security fixes any more${eol:+ (since $eol)}. Move the domain to a supported version." "$version" ;;
		security) dr_warn "PHP $version gets only security fixes${eol:+ until $eol}. Plan the move to a newer version." "$version" ;;
		active) dr_ok "PHP $version is supported." "$version" ;;
		*) dr_skip "The support state of PHP $version is unknown." "$version" ;;
	esac
}
