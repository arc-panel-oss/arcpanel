#!/bin/bash
# E17 check server.php-ini (spec §4.1): the php.ini of PHP-FPM of every
# installed version passes the checks of v-check-sys-php-ini (run on the
# file in place through php_ini_validate, without changing it).
# shellcheck disable=SC2034
DOCTOR_ID=server.php-ini
DOCTOR_SCOPE=server
DOCTOR_TITLE='The php.ini files are valid'
DOCTOR_DOCS='/docs/guides/doctor#server-php-ini'
DOCTOR_FIX_ALLOWED=''

# Findings of every installed version as lines VERSION<TAB>LEVEL<TAB>MESSAGE
doctor_php_ini_findings() {
	local version file
	# shellcheck source=/usr/local/arcpanel/func/php.sh
	source "$ARCPANEL/func/php.sh" || return 1
	for file in /etc/php/*/fpm/php.ini; do
		[ -f "$file" ] || continue
		version=$(basename "$(dirname "$(dirname "$file")")")
		command -v "php$version" > /dev/null 2>&1 || continue
		php_ini_validate "$version" fpm "$file" 2> /dev/null \
			| jq -r --arg v "$version" '.[]? | [$v, .level, ((if .directive != "" then .directive + ": " else "" end) + .message)] | @tsv'
	done
	return 0
}

doctor_check() {
	local out count versions first
	if ! out=$(doctor_src php-ini doctor_php_ini_findings 2> /dev/null); then
		dr_skip 'The php.ini files could not be checked.'
		return
	fi
	out=$(grep -v '^[[:space:]]*$' <<< "$out")
	if [ -z "$out" ]; then
		dr_ok 'The php.ini file of every installed PHP version is valid.' 0
		return
	fi
	count=$(wc -l <<< "$out")
	versions=$(cut -f1 <<< "$out" | sort -u | tr '\n' ' ')
	first=$(head -n1 <<< "$out" | cut -f3)
	if [ "$count" -eq 1 ]; then
		dr_warn "The php.ini of PHP ${versions% } has 1 finding, for example: $first" "$count"
	else
		dr_warn "The php.ini of PHP ${versions% } has $count findings, for example: $first" "$count"
	fi
}
