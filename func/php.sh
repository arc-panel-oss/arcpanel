#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: PHP Manager (E41,
# docs/features/specs/2026-10-12-001-e41-php-manager-design.md).
# The version catalog, the PHP repository's packages, extensions, php.ini
# files and PHP-FPM profiles. The catalogs ship with the panel; the state
# lives in data/php/ (root only).

PHP_CATALOG="${ARC_PHP_CATALOG:-$ARCPANEL/install/common/php/versions.conf}"
PHP_EXT_CATALOG="${ARC_PHP_EXT_CATALOG:-$ARCPANEL/install/common/php/extensions.conf}"
PHP_DATA="$ARCPANEL/data/php"
PHP_FPM_TPL_DIR="$ARCPANEL/data/templates/web/php-fpm"

# Value of KEY in a KEY='value' line (no subshell: list commands call it
# for every catalog entry)
php_kv() {
	local re="(^| )$2='([^']*)'"
	if [[ "$1" =~ $re ]]; then
		echo "${BASH_REMATCH[2]}"
	fi
}

# Whether version A <= version B (X.Y)
php_version_le() {
	local a1=${1%%.*} a2=${1#*.} b1=${2%%.*} b2=${2#*.}
	((10#$a1 < 10#$b1 || (10#$a1 == 10#$b1 && 10#$a2 <= 10#$b2)))
}

# Create data/php (root only)
php_data_dir() {
	if [ ! -d "$PHP_DATA" ]; then
		mkdir -p "$PHP_DATA"
	fi
	chown root:root "$PHP_DATA"
	chmod 750 "$PHP_DATA"
}

#----------------------------------------------------------#
#                    Version catalog                       #
#----------------------------------------------------------#

# The catalog lines by version, read once per shell
declare -gA PHP_CAT=()
PHP_CAT_LOADED=''
php_catalog_load() {
	local line re="^VERSION='([0-9]{1,2}\.[0-9]{1,2})' "
	if [ -n "$PHP_CAT_LOADED" ]; then
		return 0
	fi
	PHP_CAT_LOADED=yes
	[ -f "$PHP_CATALOG" ] || return 0
	while IFS= read -r line || [ -n "$line" ]; do
		if [[ "$line" =~ $re ]]; then
			PHP_CAT[${BASH_REMATCH[1]}]=$line
		fi
	done < "$PHP_CATALOG"
}

# Versions of the catalog, one per line, oldest first
php_catalog_versions() {
	php_catalog_load
	if [ "${#PHP_CAT[@]}" -gt 0 ]; then
		printf '%s\n' "${!PHP_CAT[@]}" | sort -V
	fi
}

# The catalog line of a version (exact match)
php_catalog_line() {
	php_catalog_load
	echo "${PHP_CAT[$1]-}"
}

# A catalog value: php_catalog_get VERSION KEY
php_catalog_get() {
	php_kv "$(php_catalog_line "$1")" "$2"
}

# X.Y with one or two digits on each side
php_version_format_valid() {
	[[ "$1" =~ ^[0-9]{1,2}\.[0-9]{1,2}$ ]]
}

# The format is right and the catalog lists the version (exact match)
php_version_valid() {
	php_version_format_valid "$1" || return 1
	php_catalog_load
	[ -n "${PHP_CAT[$1]+set}" ]
}

# Listed with INSTALLABLE='yes'
php_version_installable() {
	php_version_valid "$1" && [ "$(php_catalog_get "$1" INSTALLABLE)" = 'yes' ]
}

# active before ACTIVE_UNTIL, security before EOL, eol after; unknown for a
# version the catalog does not list
php_version_status() {
	local today active eol
	today=$(date +%F)
	active=$(php_catalog_get "$1" ACTIVE_UNTIL)
	eol=$(php_catalog_get "$1" EOL)
	if [ -z "$eol" ]; then
		echo 'unknown'
	elif [[ ! "$today" > "$active" ]]; then
		echo 'active'
	elif [[ ! "$today" > "$eol" ]]; then
		echo 'security'
	else
		echo 'eol'
	fi
}

# Backend template name of a version: 8.3 -> PHP-8_3
php_tpl_name() {
	echo "PHP-${1/./_}"
}

# Whether a version is installed: its PHP-FPM init script and its backend
# template exist
php_version_installed() {
	php_version_format_valid "$1" \
		&& [ -f "/etc/init.d/php$1-fpm" ] \
		&& [ -f "$PHP_FPM_TPL_DIR/$(php_tpl_name "$1").tpl" ]
}

# Installed versions (PHP-FPM), one per line, oldest first
php_installed_versions() {
	local dir version
	for dir in /etc/php/*/fpm; do
		[ -d "$dir" ] || continue
		version=$(basename "$(dirname "$dir")")
		if php_version_installed "$version"; then
			echo "$version"
		fi
	done | sort -V
}

# The version of the system php binary (update-alternatives)
php_default_version() {
	php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2> /dev/null
}

# The version a backend template names (PHP-X_Y, also at the end of a custom
# template name), or the default PHP-FPM version
php_backend_version() {
	if [[ "$1" =~ ^.*PHP-([0-9]{1,2})_([0-9]{1,2})$ ]]; then
		echo "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
	else
		multiphp_default_version
	fi
}

# Web domains that use a version, one USER/DOMAIN per line: the backend
# template PHP-X_Y, a custom template ending in -PHP-X_Y, or "default" for the
# default version
php_used_by() {
	local version="$1" tpl conf user default line domain backend
	tpl=$(php_tpl_name "$version")
	default=$(php_default_version)
	for conf in "$ARCPANEL"/data/users/*/web.conf; do
		[ -f "$conf" ] || continue
		user=$(basename "$(dirname "$conf")")
		while IFS= read -r line; do
			domain=$(php_kv "$line" DOMAIN)
			backend=$(php_kv "$line" BACKEND)
			[ -n "$domain" ] || continue
			if [ "$backend" = "$tpl" ] || [[ "$backend" == *"-$tpl" ]] \
				|| { [ "$backend" = 'default' ] && [ "$version" = "$default" ]; }; then
				echo "$user/$domain"
			fi
		done < "$conf"
	done
}

#----------------------------------------------------------#
#                   PHP repository                         #
#----------------------------------------------------------#

PHP_APT_CACHE_TTL=600

php_apt_cache_file() {
	echo "$PHP_DATA/apt-cache.conf"
}

# Forget the cached install candidates (after apt-get update)
php_apt_cache_clear() {
	rm -f "$(php_apt_cache_file)"
	PHP_APT=()
}

# Look up the install candidates of packages that the cache does not hold or
# holds for longer than ten minutes, in one apt-cache call
php_apt_refresh() {
	local file now pkg line time out current tmp
	local -a missing=()
	local -A found=() cached=()
	local re="^PACKAGE='([^']+)' CANDIDATE='[a-z]+' TIME='([0-9]+)'"
	file=$(php_apt_cache_file)
	now=$(date +%s)
	if [ -f "$file" ]; then
		while IFS= read -r line; do
			if [[ "$line" =~ $re ]]; then
				cached[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
			fi
		done < "$file"
	fi
	for pkg in "$@"; do
		time=${cached[$pkg]-}
		if [ -z "$time" ] || [ $((now - time)) -ge "$PHP_APT_CACHE_TTL" ]; then
			missing+=("$pkg")
		fi
	done
	if [ "${#missing[@]}" -eq 0 ]; then
		php_apt_load
		return 0
	fi
	out=$(LC_ALL=C apt-cache policy "${missing[@]}" 2> /dev/null)
	current=''
	while IFS= read -r line; do
		if [[ "$line" =~ ^([a-z0-9][a-z0-9.+-]*)(:[a-z0-9]+)?:$ ]]; then
			current=${BASH_REMATCH[1]}
		elif [ -n "$current" ] && [[ "$line" =~ ^[[:space:]]+Candidate:[[:space:]]*(.*)$ ]]; then
			if [ "${BASH_REMATCH[1]}" = '(none)' ]; then
				found[$current]=no
			else
				found[$current]=yes
			fi
		fi
	done <<< "$out"
	php_data_dir
	(
		flock -x 9
		tmp=$(mktemp "$file.XXXXXX")
		if [ -f "$file" ]; then
			grep -vF -f <(printf "PACKAGE='%s' \n" "${missing[@]}") "$file" > "$tmp"
		fi
		for pkg in "${missing[@]}"; do
			echo "PACKAGE='$pkg' CANDIDATE='${found[$pkg]:-no}' TIME='$now'" >> "$tmp"
		done
		chmod 640 "$tmp"
		mv -f "$tmp" "$file"
	) 9> "$file.lock"
	php_apt_load
}

# The cached candidates by package, read after each refresh
declare -gA PHP_APT=()
php_apt_load() {
	local line re="^PACKAGE='([^']+)' CANDIDATE='([a-z]+)' "
	PHP_APT=()
	[ -f "$(php_apt_cache_file)" ] || return 0
	while IFS= read -r line; do
		if [[ "$line" =~ $re ]]; then
			PHP_APT[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
		fi
	done < "$(php_apt_cache_file)"
}

# Whether a package has an install candidate in the configured repositories;
# a package that php_apt_refresh looked up in this shell is not read again
php_apt_candidate() {
	if [ -z "${PHP_APT[$1]+set}" ]; then
		php_apt_refresh "$1"
	fi
	[ "${PHP_APT[$1]-}" = 'yes' ]
}

# Whether the PHP repository of the server provides a version (phpX.Y-fpm)
php_version_available() {
	php_apt_candidate "php$1-fpm"
}

# Installed Debian packages among the arguments, one per line
php_dpkg_installed() {
	dpkg-query -W -f='${Package} ${db:Status-Status}\n' "$@" 2> /dev/null | awk '$2 == "installed" { print $1 }'
}

#----------------------------------------------------------#
#                 Services and checks                      #
#----------------------------------------------------------#

# Lock of the changes to one version (extensions, php.ini): php_lock VERSION
# opens fd 8 and waits for the lock
php_lock() {
	php_data_dir
	exec 8> "$PHP_DATA/php$1.lock"
	flock -x 8
}

# php-fpmX.Y -t with an optional php.ini; prints the output and fails on a
# failed test or a startup warning (an extension that does not load)
php_fpm_test() {
	local version="$1" ini="$2" out rc
	local -a args=(-t)
	if [ -n "$ini" ]; then
		args+=(-c "$ini")
	fi
	out=$("php-fpm$version" "${args[@]}" "${PHP_INI_SAFE_RUN[@]}" 2>&1)
	rc=$?
	if [ "$rc" -ne 0 ] || grep -qiE 'PHP (Startup|Warning|Fatal error|Parse error)|ERROR:' <<< "$out"; then
		echo "$out"
		return 1
	fi
}

# The CLI binary loads its modules without a startup warning
php_cli_test() {
	local out
	out=$(
		"php$1" -d display_startup_errors=1 -m 2>&1 > /dev/null
		"php$1" -d display_startup_errors=1 -r 'exit(0);' 2>&1
	)
	if grep -qiE 'PHP (Startup|Warning|Fatal error|Parse error)|Warning:' <<< "$out"; then
		echo "$out"
		return 1
	fi
}

# Whether the PHP-FPM service of a version runs. ARC_PHP_TEST_INACTIVE (a
# version) makes the check fail, for the BATS suites; sudo drops it, so the
# panel cannot set it
php_fpm_active() {
	if [ -n "$ARC_PHP_TEST_INACTIVE" ] && [ "$ARC_PHP_TEST_INACTIVE" = "$1" ]; then
		return 1
	fi
	if command -v systemctl > /dev/null 2>&1 && [ -d /run/systemd/system ]; then
		systemctl is-active --quiet "php$1-fpm"
	else
		service "php$1-fpm" status > /dev/null 2>&1
	fi
}

# Reload PHP-FPM of a version and check that it still runs three seconds later
php_fpm_reload() {
	"$BIN/v-restart-web-backend" yes "$1" > /dev/null 2>&1
	sleep "${ARC_PHP_HEALTH_DELAY:-3}"
	php_fpm_active "$1"
}

# The unit's last log lines, for an E_RESTART message
php_fpm_log() {
	journalctl -u "php$1-fpm" -n 10 --no-pager 2> /dev/null | tail -n 10
}

#----------------------------------------------------------#
#                      Extensions                          #
#----------------------------------------------------------#

# Catalog lines (no comments)
php_ext_lines() {
	grep -E "^NAME='[a-z0-9_]{1,32}' " "$PHP_EXT_CATALOG" 2> /dev/null
}

# The catalog lines by name, and the names in catalog order, read once per
# shell
declare -gA PHP_EXT=()
PHP_EXT_NAMES=()
PHP_EXT_LOADED=''
php_ext_load() {
	local line re="^NAME='([a-z0-9_]{1,32})' "
	if [ -n "$PHP_EXT_LOADED" ]; then
		return 0
	fi
	PHP_EXT_LOADED=yes
	[ -f "$PHP_EXT_CATALOG" ] || return 0
	while IFS= read -r line || [ -n "$line" ]; do
		if [[ "$line" =~ $re ]] && [ -z "${PHP_EXT[${BASH_REMATCH[1]}]+set}" ]; then
			PHP_EXT[${BASH_REMATCH[1]}]=$line
			PHP_EXT_NAMES+=("${BASH_REMATCH[1]}")
		fi
	done < "$PHP_EXT_CATALOG"
}

# The catalog line of an extension (exact match)
php_ext_line() {
	php_ext_load
	echo "${PHP_EXT[$1]-}"
}

php_ext_name_valid() {
	[[ "$1" =~ ^[a-z0-9_]{1,32}$ ]] || return 1
	php_ext_load
	[ -n "${PHP_EXT[$1]+set}" ]
}

# A catalog value: php_ext_get NAME KEY
php_ext_get() {
	php_kv "$(php_ext_line "$1")" "$2"
}

# The Debian package of an extension for a version, empty for a toggle
php_ext_package() {
	local pkg
	pkg=$(php_ext_get "$1" PACKAGE)
	echo "${pkg//%v/$2}"
}

# Whether a catalog entry applies to a version (MIN and MAX)
php_ext_applies() {
	local min max
	min=$(php_ext_get "$1" MIN)
	max=$(php_ext_get "$1" MAX)
	{ [ -z "$min" ] || php_version_le "$min" "$2"; } && { [ -z "$max" ] || php_version_le "$2" "$max"; }
}

# Names of the catalog entries that apply to a version
php_ext_names() {
	local name
	php_ext_load
	for name in "${PHP_EXT_NAMES[@]}"; do
		if php_ext_applies "$name" "$1"; then
			echo "$name"
		fi
	done
}

# Whether the module of an extension is present for a version
php_mod_present() {
	[ -f "/etc/php/$1/mods-available/$2.ini" ]
}

# Modules enabled for a SAPI of a version, one per line: the PRIORITY-MODULE.ini
# links that phpenmod puts into conf.d (phpquery -M also lists disabled ones)
php_mods_enabled() {
	local file module
	for file in "/etc/php/$1/$2/conf.d/"*.ini; do
		[ -e "$file" ] || continue
		module=${file##*/}
		module=${module%.ini}
		module=${module#*-}
		if [[ "$module" =~ ^[A-Za-z0-9_]+$ ]] && [ -f "/etc/php/$1/mods-available/$module.ini" ]; then
			echo "$module"
		fi
	done | sort -u
}

php_mod_enabled() {
	php_mods_enabled "$1" "$2" | grep -qxF "$3"
}

# Modules compiled into the PHP binary of a version, one per line in lower
# case: they have no mods-available file and are always on (sodium in the
# Sury builds, opcache since PHP 8.5)
php_mods_builtin() {
	[ -x "/usr/bin/php$1" ] || return 0
	"/usr/bin/php$1" -n -m 2> /dev/null | sed -n '/^\[PHP Modules\]/,/^\[Zend Modules\]/p' \
		| grep -v '^\[' | tr '[:upper:]' '[:lower:]' | sed -e 's/^zend opcache$/opcache/' -e '/^$/d' | sort -u
}

# Whether the module of an extension is built into the binary of a version
php_ext_builtin() {
	local module
	module=$(php_ext_get "$1" MODULE)
	! php_mod_present "$2" "$module" && php_mods_builtin "$2" | grep -qxF "$module"
}

# Whether an extension is installed for a version: its package, or for a
# toggle its module
php_ext_installed() {
	local pkg
	pkg=$(php_ext_package "$1" "$2")
	if [ -n "$pkg" ]; then
		[ -n "$(php_dpkg_installed "$pkg")" ] && php_mod_present "$2" "$(php_ext_get "$1" MODULE)"
	else
		php_mod_present "$2" "$(php_ext_get "$1" MODULE)"
	fi
}

# Whether an extension can be installed for a version: its package has an
# install candidate, or for a toggle its module is present
php_ext_available() {
	local pkg
	pkg=$(php_ext_package "$1" "$2")
	if [ -n "$pkg" ]; then
		php_apt_candidate "$pkg"
	else
		php_mod_present "$2" "$(php_ext_get "$1" MODULE)"
	fi
}

# Conflicting extensions of NAME that are enabled for FPM or CLI, one per line
php_ext_conflicts_enabled() {
	local name="$1" version="$2" other module
	for other in $(php_ext_get "$name" CONFLICTS | tr ',' ' '); do
		php_ext_name_valid "$other" || continue
		module=$(php_ext_get "$other" MODULE)
		if php_mod_enabled "$version" fpm "$module" || php_mod_enabled "$version" cli "$module"; then
			echo "$other"
		fi
	done
}

# Validate VERSION (installed) and NAME (catalog entry for that version)
php_ext_check_args() {
	if ! php_version_format_valid "$1"; then
		check_result "$E_INVALID" "invalid PHP version :: $1"
	fi
	if ! php_version_installed "$1"; then
		check_result "$E_NOTEXIST" "PHP $1 is not installed"
	fi
	if [ -n "${2+x}" ]; then
		if ! php_ext_name_valid "$2"; then
			check_result "$E_INVALID" "unknown PHP extension :: $2"
		fi
		if ! php_ext_applies "$2" "$1"; then
			check_result "$E_INVALID" "the extension $2 does not apply to PHP $1"
		fi
	fi
}

#----------------------------------------------------------#
#                       php.ini                            #
#----------------------------------------------------------#

PHP_INI_HELPER="$ARCPANEL/func/php-ini.php"
PHP_INI_MAX_SIZE=1048576
PHP_INI_KEEP=10

php_ini_file() {
	echo "/etc/php/$1/$2/php.ini"
}

php_ini_backup_dir() {
	echo "$PHP_DATA/ini-backups/$1/$2"
}

# Validate VERSION (installed) and SAPI (fpm or cli)
php_ini_check_args() {
	if ! php_version_format_valid "$1"; then
		check_result "$E_INVALID" "invalid PHP version :: $1"
	fi
	if ! php_version_installed "$1"; then
		check_result "$E_NOTEXIST" "PHP $1 is not installed"
	fi
	if [ "$2" != 'fpm' ] && [ "$2" != 'cli' ]; then
		check_result "$E_INVALID" "invalid SAPI :: $2 (fpm or cli)"
	fi
	if [ ! -f "$(php_ini_file "$1" "$2")" ]; then
		check_result "$E_NOTEXIST" "$(php_ini_file "$1" "$2") does not exist"
	fi
}

# A private temp file under data/php/tmp (root, 0600)
php_tmp_file() {
	php_data_dir
	mkdir -p "$PHP_DATA/tmp"
	chmod 700 "$PHP_DATA/tmp"
	mktemp "$PHP_DATA/tmp/$1.XXXXXX"
}

# Read a file given by the caller once into a private temp file, whose path
# goes into PHP_INI_CANDIDATE: a regular file of at most 1 MiB without NUL
# bytes (§11)
php_ini_intake() {
	local src="$1" tmp
	if [ ! -f "$src" ]; then
		check_result "$E_NOTEXIST" "$src does not exist"
	fi
	if [ "$(stat -c %s "$src")" -gt "$PHP_INI_MAX_SIZE" ]; then
		check_result "$E_INVALID" "the file is larger than 1 MiB"
	fi
	tmp=$(php_tmp_file ini)
	cat "$src" > "$tmp"
	if [ "$(tr -d '\000' < "$tmp" | wc -c)" -ne "$(stat -c %s "$tmp")" ]; then
		rm -f "$tmp"
		check_result "$E_INVALID" "the file contains NUL bytes"
	fi
	PHP_INI_CANDIDATE=$tmp
}

# Settings of every run of PHP with a candidate php.ini as root: no opcache
# and no preload script, no FFI (E39 W5 P2)
PHP_INI_SAFE_RUN=(-d opcache.enable_cli=0 -d opcache.preload= -d opcache.preload_user= -d ffi.enable=0 -d ffi.preload=)

# Run the helper with the version's binary and no php.ini
php_ini_helper() {
	local version="$1"
	shift
	"php$version" -n -d display_errors=stderr "$PHP_INI_HELPER" "$@"
}

# Validate a candidate php.ini (§6.3 steps 1-3) and print the findings as a
# JSON array of {line, level, directive, message}; fails when one of them is
# an error
php_ini_validate() {
	local version="$1" sapi="$2" file="$3" scan dir out known level line
	local -a parts=()
	scan="/etc/php/$version/$sapi/conf.d"
	dir=$(php_tmp_file check)
	rm -f "$dir"
	mkdir -m 700 "$dir"

	# 1-2. Syntax and semantics of known directives
	if ! php_ini_helper "$version" check "$file" "$sapi" > "$dir/semantic.json" 2> "$dir/err" \
		|| ! jq -e 'type == "array"' "$dir/semantic.json" > /dev/null 2>&1; then
		jq -n --arg m "$(head -c 500 "$dir/err")" '[{line: 0, level: "error", directive: "", message: ("The check failed: " + $m)}]'
		rm -rf "$dir"
		return 1
	fi
	parts+=("$dir/semantic.json")

	# PHP runs with the candidate only when the check found no error at all:
	# root runs it, so it loads only extensions of the version's folder and
	# no preload script (E39 W5 P2)
	if ! jq -e 'any(.[]; .level == "error")' "$dir/semantic.json" > /dev/null; then
		# Unknown directives, with the extensions the candidate loads
		PHP_INI_SCAN_DIR="$scan" "php$version" -c "$file" "${PHP_INI_SAFE_RUN[@]}" -d display_startup_errors=0 -d display_errors=0 \
			-d log_errors=0 -d disable_functions= -d disable_classes= -d open_basedir= \
			-d auto_prepend_file= -d auto_append_file= "$PHP_INI_HELPER" known > "$dir/known.json" 2> /dev/null
		if jq -e 'type == "array"' "$dir/known.json" > /dev/null 2>&1; then
			php_ini_helper "$version" unknown "$file" "$dir/known.json" "$(php_ini_file "$version" "$sapi")" > "$dir/unknown.json" 2> /dev/null \
				&& parts+=("$dir/unknown.json")
		fi

		# 3. PHP starts with the candidate: no startup warning (an extension
		# that does not load); deprecations are warnings.
		echo '[]' > "$dir/start.json"
		out=$(PHP_INI_SCAN_DIR="$scan" "php$version" -c "$file" "${PHP_INI_SAFE_RUN[@]}" -d display_startup_errors=1 \
			-d display_errors=stderr -d log_errors=0 -d auto_prepend_file= -d auto_append_file= \
			-r 'exit(0);' 2>&1)
		while IFS= read -r line; do
			line=$(sed -e 's/^PHP //' <<< "$line")
			[ -n "${line// /}" ] || continue
			level=error
			if [[ "$line" == *Deprecated* ]]; then
				level=warning
			fi
			jq -c --arg l "$level" --arg m "$line" '. + [{line: 0, level: $l, directive: "", message: $m}]' \
				"$dir/start.json" > "$dir/start.tmp" && mv -f "$dir/start.tmp" "$dir/start.json"
		done <<< "$out"
		parts+=("$dir/start.json")

		if [ "$sapi" = 'fpm' ] && ! jq -s -e 'add | any(.[]; .level == "error")' "$dir/semantic.json" "$dir/start.json" > /dev/null; then
			if ! out=$(php_fpm_test "$version" "$file"); then
				jq -n --arg m "php-fpm$version -t failed: $(grep -vE 'NOTICE' <<< "$out" | head -n 3 | tr '\n' ' ')" \
					'[{line: 0, level: "error", directive: "", message: $m}]' > "$dir/fpm.json"
				parts+=("$dir/fpm.json")
			fi
		fi
	fi

	jq -s 'add | sort_by(.line)' "${parts[@]}" > "$dir/all.json"
	cat "$dir/all.json"
	if jq -e 'any(.[]; .level == "error")' "$dir/all.json" > /dev/null; then
		rm -rf "$dir"
		return 1
	fi
	rm -rf "$dir"
}

# Findings as text lines: "line 12: error: message"
php_ini_findings_text() {
	jq -r '.[] | "line \(.line): \(.level): \(.message)"'
}

# Remove all but the ten newest backups of a file
php_ini_prune() {
	local dir file
	dir=$(php_ini_backup_dir "$1" "$2")
	for file in "$dir"/*.ini; do
		file=${file##*/}
		if [[ "$file" =~ ^[0-9]{8}-[0-9]{6}(-[0-9]+)?\.ini$ ]]; then
			echo "$file"
		fi
	done | sort -V | head -n "-$PHP_INI_KEEP" | while read -r old; do
		rm -f "${dir:?}/$old"
	done
}

# §6.3 step 4: back up the current file, replace it atomically, reload
# PHP-FPM and check it runs; restore the previous file when it does not.
# Prints the backup id; returns E_RESTART after a rollback.
php_ini_apply() {
	local version="$1" sapi="$2" candidate="$3" target dir id
	target=$(php_ini_file "$version" "$sapi")
	dir=$(php_ini_backup_dir "$version" "$sapi")
	php_data_dir
	mkdir -p "$dir"
	chmod 750 "$PHP_DATA/ini-backups" "$PHP_DATA/ini-backups/$version" "$dir"
	id=$(date +%Y%m%d-%H%M%S)
	if [ -e "$dir/$id.ini" ]; then
		local n=2
		while [ -e "$dir/$id-$n.ini" ]; do
			n=$((n + 1))
		done
		id="$id-$n"
	fi
	cp -p "$target" "$dir/$id.ini"
	chmod 640 "$dir/$id.ini"

	cp "$candidate" "$target.arcpanel-new"
	chown root:root "$target.arcpanel-new"
	chmod 644 "$target.arcpanel-new"
	mv -f "$target.arcpanel-new" "$target"
	php_ini_prune "$version" "$sapi"

	if [ "$sapi" = 'fpm' ] && ! php_fpm_reload "$version"; then
		php_fpm_log "$version"
		cp "$dir/$id.ini" "$target.arcpanel-new"
		chmod 644 "$target.arcpanel-new"
		mv -f "$target.arcpanel-new" "$target"
		"$BIN/v-restart-service" "php$version-fpm" > /dev/null 2>&1
		return "$E_RESTART"
	fi
	echo "$id"
}

#----------------------------------------------------------#
#                  PHP-FPM profiles                        #
#----------------------------------------------------------#

PHP_PROFILE_KEYS="NAME DESCRIPTION PM MAX_CHILDREN START_SERVERS MIN_SPARE MAX_SPARE MAX_REQUESTS IDLE_TIMEOUT REQUEST_TERMINATE_TIMEOUT MEMORY_LIMIT UPLOAD_MAX_FILESIZE POST_MAX_SIZE MAX_EXECUTION_TIME MAX_INPUT_VARS BUILTIN"
# Profile keys written as php_admin_value lines, with their directive
PHP_PROFILE_LIMITS="MEMORY_LIMIT:memory_limit UPLOAD_MAX_FILESIZE:upload_max_filesize POST_MAX_SIZE:post_max_size MAX_EXECUTION_TIME:max_execution_time MAX_INPUT_VARS:max_input_vars"
PHP_PROFILE_PLACEHOLDER='%php_profile%'

php_profiles_file() {
	echo "$PHP_DATA/profiles.conf"
}

# The four shipped profiles (§7.1); "default" renders the pool lines of the
# templates before E41
php_profiles_builtin() {
	cat << 'EOF'
NAME='default' DESCRIPTION='The pool settings of ArcPanel before profiles' PM='ondemand' MAX_CHILDREN='8' START_SERVERS='' MIN_SPARE='' MAX_SPARE='' MAX_REQUESTS='4000' IDLE_TIMEOUT='10s' REQUEST_TERMINATE_TIMEOUT='' MEMORY_LIMIT='' UPLOAD_MAX_FILESIZE='' POST_MAX_SIZE='' MAX_EXECUTION_TIME='' MAX_INPUT_VARS='' BUILTIN='yes'
NAME='small' DESCRIPTION='Small sites with little traffic' PM='ondemand' MAX_CHILDREN='4' START_SERVERS='' MIN_SPARE='' MAX_SPARE='' MAX_REQUESTS='500' IDLE_TIMEOUT='10s' REQUEST_TERMINATE_TIMEOUT='120s' MEMORY_LIMIT='128M' UPLOAD_MAX_FILESIZE='32M' POST_MAX_SIZE='32M' MAX_EXECUTION_TIME='60' MAX_INPUT_VARS='2000' BUILTIN='yes'
NAME='medium' DESCRIPTION='Busy sites' PM='dynamic' MAX_CHILDREN='20' START_SERVERS='4' MIN_SPARE='2' MAX_SPARE='6' MAX_REQUESTS='1000' IDLE_TIMEOUT='' REQUEST_TERMINATE_TIMEOUT='300s' MEMORY_LIMIT='512M' UPLOAD_MAX_FILESIZE='128M' POST_MAX_SIZE='128M' MAX_EXECUTION_TIME='300' MAX_INPUT_VARS='5000' BUILTIN='yes'
NAME='large' DESCRIPTION='Large shops and applications' PM='dynamic' MAX_CHILDREN='50' START_SERVERS='10' MIN_SPARE='5' MAX_SPARE='15' MAX_REQUESTS='2000' IDLE_TIMEOUT='' REQUEST_TERMINATE_TIMEOUT='600s' MEMORY_LIMIT='1024M' UPLOAD_MAX_FILESIZE='512M' POST_MAX_SIZE='512M' MAX_EXECUTION_TIME='600' MAX_INPUT_VARS='10000' BUILTIN='yes'
EOF
}

# Create the profile store with the shipped profiles; a shipped profile that
# is missing (deleted, or added in a later release) comes back except when an
# administrator deleted it (data/php/profiles.deleted)
php_profiles_ensure() {
	local file line name
	file=$(php_profiles_file)
	if [ -s "$file" ] && grep -q "^NAME='default' " "$file"; then
		return 0
	fi
	php_data_dir
	(
		flock -x 9
		touch "$file"
		while IFS= read -r line; do
			name=$(php_kv "$line" NAME)
			if ! grep -q "^NAME='$name' " "$file" \
				&& { [ "$name" = 'default' ] || ! grep -qx "$name" "$PHP_DATA/profiles.deleted" 2> /dev/null; }; then
				echo "$line" >> "$file"
			fi
		done < <(php_profiles_builtin)
		chmod 644 "$file"
	) 9> "$(php_profiles_file).lock"
}

php_profile_names() {
	php_profiles_ensure
	grep -oE "^NAME='[a-z0-9][a-z0-9-]{0,31}'" "$(php_profiles_file)" | cut -d\' -f2
}

php_profile_line() {
	php_profiles_ensure
	grep -F "NAME='$1' " "$(php_profiles_file)" | grep -E "^NAME=" | head -n1
}

php_profile_name_valid() {
	[[ "$1" =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]]
}

php_profile_exists() {
	php_profile_name_valid "$1" && [ -n "$(php_profile_line "$1")" ]
}

php_profile_get() {
	php_kv "$(php_profile_line "$1")" "$2"
}

# The pool lines of a profile (§7.3)
php_profile_block() {
	local line pm pair key directive value
	line=$(php_profile_line "$1")
	if [ -z "$line" ]; then
		line=$(php_profile_line default)
	fi
	pm=$(php_kv "$line" PM)
	echo "pm = $pm"
	echo "pm.max_children = $(php_kv "$line" MAX_CHILDREN)"
	if [ "$pm" = 'dynamic' ]; then
		echo "pm.start_servers = $(php_kv "$line" START_SERVERS)"
		echo "pm.min_spare_servers = $(php_kv "$line" MIN_SPARE)"
		echo "pm.max_spare_servers = $(php_kv "$line" MAX_SPARE)"
	fi
	echo "pm.max_requests = $(php_kv "$line" MAX_REQUESTS)"
	if [ "$pm" = 'ondemand' ]; then
		echo "pm.process_idle_timeout = $(php_kv "$line" IDLE_TIMEOUT)"
	fi
	value=$(php_kv "$line" REQUEST_TERMINATE_TIMEOUT)
	if [ -n "$value" ]; then
		echo "request_terminate_timeout = $value"
	fi
	for pair in $PHP_PROFILE_LIMITS; do
		key=${pair%%:*}
		directive=${pair#*:}
		value=$(php_kv "$line" "$key")
		if [ -n "$value" ]; then
			echo "php_admin_value[$directive] = $value"
		fi
	done
}

# Whether a pool template has the profile placeholder
php_template_has_profile() {
	grep -qE "^[[:space:]]*${PHP_PROFILE_PLACEHOLDER}[[:space:]]*$" "$1" 2> /dev/null
}

# A pool template with the placeholder replaced by the lines of a profile
# and, with USER and KEY (the sidecar key of the pool), the domain's
# settings after them (E19 spec §4); a template without the placeholder is
# printed as it is
php_pool_template() {
	local tpl="$1" profile="$2" user="${3-}" key="${4-}" block settings
	block=$(php_profile_block "$profile")
	if [ -n "$user" ] && [ -n "$key" ]; then
		settings=$(php_domain_settings_block "$user" "$key")
		if [ -n "$settings" ]; then
			block="$block"$'\n'"$settings"
		fi
	fi
	PHP_POOL_BLOCK="$block" awk -v ph="$PHP_PROFILE_PLACEHOLDER" '
		{ line = $0; gsub(/^[ \t]+|[ \t]+$/, "", line) }
		line == ph { print ENVIRON["PHP_POOL_BLOCK"]; next }
		{ print }
	' "$tpl"
}

# Size in bytes for comparisons: 128M -> 134217728; -1 stays -1
php_size_bytes() {
	if [[ "$1" =~ ^(-?[0-9]+)([KMGkmg]?)$ ]]; then
		local n=${BASH_REMATCH[1]}
		case "${BASH_REMATCH[2]^^}" in
			G) n=$((n * 1073741824)) ;;
			M) n=$((n * 1048576)) ;;
			K) n=$((n * 1024)) ;;
		esac
		echo "$n"
	fi
}

# Validate the profile in the associative array PROFILE (keys of §7.1);
# clears the keys the process manager does not use. Fails with the first
# error in PHP_ERROR.
php_profile_validate() {
	local key value post upload memory
	for key in DESCRIPTION PM MAX_CHILDREN START_SERVERS MIN_SPARE MAX_SPARE MAX_REQUESTS IDLE_TIMEOUT \
		REQUEST_TERMINATE_TIMEOUT MEMORY_LIMIT UPLOAD_MAX_FILESIZE POST_MAX_SIZE MAX_EXECUTION_TIME MAX_INPUT_VARS; do
		value=${PROFILE[$key]-}
		if [[ "$value" == *"'"* || "$value" == *$'\n'* || "$value" == *'\'* ]]; then
			PHP_ERROR="$key contains a quote, a backslash or a line break"
			return 1
		fi
	done
	if [ "${#PROFILE[DESCRIPTION]}" -gt 100 ]; then
		PHP_ERROR="DESCRIPTION is longer than 100 characters"
		return 1
	fi
	case "${PROFILE[PM]-}" in
		ondemand | dynamic | static) ;;
		*)
			PHP_ERROR="PM must be ondemand, dynamic or static"
			return 1
			;;
	esac
	if [[ ! "${PROFILE[MAX_CHILDREN]-}" =~ ^[0-9]{1,3}$ ]] || [ "${PROFILE[MAX_CHILDREN]}" -lt 1 ] || [ "${PROFILE[MAX_CHILDREN]}" -gt 500 ]; then
		PHP_ERROR="MAX_CHILDREN must be between 1 and 500"
		return 1
	fi
	if [[ ! "${PROFILE[MAX_REQUESTS]-}" =~ ^[0-9]{1,6}$ ]] || [ "${PROFILE[MAX_REQUESTS]}" -gt 100000 ]; then
		PHP_ERROR="MAX_REQUESTS must be between 0 and 100000"
		return 1
	fi
	if [ "${PROFILE[PM]}" = 'dynamic' ]; then
		for key in START_SERVERS MIN_SPARE MAX_SPARE; do
			if [[ ! "${PROFILE[$key]-}" =~ ^[0-9]{1,3}$ ]] || [ "${PROFILE[$key]}" -lt 1 ]; then
				PHP_ERROR="$key must be a number of at least 1 for PM dynamic"
				return 1
			fi
		done
		if [ "${PROFILE[MIN_SPARE]}" -gt "${PROFILE[START_SERVERS]}" ] \
			|| [ "${PROFILE[START_SERVERS]}" -gt "${PROFILE[MAX_SPARE]}" ] \
			|| [ "${PROFILE[MAX_SPARE]}" -gt "${PROFILE[MAX_CHILDREN]}" ]; then
			PHP_ERROR="MIN_SPARE <= START_SERVERS <= MAX_SPARE <= MAX_CHILDREN must hold"
			return 1
		fi
	else
		PROFILE[START_SERVERS]=''
		PROFILE[MIN_SPARE]=''
		PROFILE[MAX_SPARE]=''
	fi
	if [ "${PROFILE[PM]}" = 'ondemand' ]; then
		PROFILE[IDLE_TIMEOUT]=${PROFILE[IDLE_TIMEOUT]:-10s}
		if [[ ! "${PROFILE[IDLE_TIMEOUT]}" =~ ^[0-9]{1,5}s$ ]]; then
			PHP_ERROR="IDLE_TIMEOUT must be seconds such as 10s"
			return 1
		fi
	else
		PROFILE[IDLE_TIMEOUT]=''
	fi
	if [ -n "${PROFILE[REQUEST_TERMINATE_TIMEOUT]-}" ] && [[ ! "${PROFILE[REQUEST_TERMINATE_TIMEOUT]}" =~ ^[0-9]{1,5}s$ ]]; then
		PHP_ERROR="REQUEST_TERMINATE_TIMEOUT must be empty or seconds such as 300s"
		return 1
	fi
	value=${PROFILE[MEMORY_LIMIT]-}
	if [ -n "$value" ] && [[ ! "$value" =~ ^[0-9]{1,6}[KMG]?$ ]] && [ "$value" != '-1' ]; then
		PHP_ERROR="MEMORY_LIMIT must be empty, -1 or a size such as 256M"
		return 1
	fi
	for key in UPLOAD_MAX_FILESIZE POST_MAX_SIZE; do
		value=${PROFILE[$key]-}
		if [ -n "$value" ] && [[ ! "$value" =~ ^[0-9]{1,6}[KMG]?$ ]]; then
			PHP_ERROR="$key must be empty or a size such as 64M"
			return 1
		fi
	done
	value=${PROFILE[MAX_EXECUTION_TIME]-}
	if [ -n "$value" ] && [[ ! "$value" =~ ^[0-9]{1,6}$ ]]; then
		PHP_ERROR="MAX_EXECUTION_TIME must be empty or seconds"
		return 1
	fi
	value=${PROFILE[MAX_INPUT_VARS]-}
	if [ -n "$value" ] && { [[ ! "$value" =~ ^[0-9]{1,7}$ ]] || [ "$value" -lt 1 ]; }; then
		PHP_ERROR="MAX_INPUT_VARS must be empty or a number of at least 1"
		return 1
	fi
	post=$(php_size_bytes "${PROFILE[POST_MAX_SIZE]-}")
	upload=$(php_size_bytes "${PROFILE[UPLOAD_MAX_FILESIZE]-}")
	if [ -n "$post" ] && [ -n "$upload" ] && [ "$post" -gt 0 ] && [ "$post" -lt "$upload" ]; then
		PHP_ERROR="POST_MAX_SIZE must be at least UPLOAD_MAX_FILESIZE"
		return 1
	fi
}

# Warnings of a valid profile, one per line: memory_limit below post_max_size,
# and MAX_CHILDREN x MEMORY_LIMIT above the server's memory (§7.2)
php_profile_warnings() {
	local post memory ram
	post=$(php_size_bytes "${PROFILE[POST_MAX_SIZE]-}")
	memory=$(php_size_bytes "${PROFILE[MEMORY_LIMIT]-}")
	if [ -n "$post" ] && [ -n "$memory" ] && [ "$memory" -ge 0 ] && [ "$post" -gt 0 ] && [ "$memory" -lt "$post" ]; then
		echo "Warning: MEMORY_LIMIT is lower than POST_MAX_SIZE"
	fi
	ram=$(awk '/^MemTotal:/ { print $2 * 1024 }' /proc/meminfo 2> /dev/null)
	if [ -n "$memory" ] && [ "$memory" -gt 0 ] && [ -n "$ram" ] \
		&& [ "$((memory * PROFILE[MAX_CHILDREN]))" -gt "$ram" ]; then
		echo "Warning: MAX_CHILDREN x MEMORY_LIMIT ($((memory * PROFILE[MAX_CHILDREN] / 1048576))M) is more than the memory of the server ($((ram / 1048576))M)"
	fi
}

# Read a KEY='value' file into PROFILE; unknown keys fail (PHP_ERROR)
php_profile_read() {
	local file="$1" line key n=0
	local re="^([A-Z_]+)='([^']*)'$"
	PROFILE=()
	while IFS= read -r line || [ -n "$line" ]; do
		n=$((n + 1))
		line=${line%$'\r'}
		[ -n "${line// /}" ] || continue
		if [[ ! "$line" =~ $re ]]; then
			PHP_ERROR="line $n is not a KEY='value' line"
			return 1
		fi
		key=${BASH_REMATCH[1]}
		case " $PHP_PROFILE_KEYS " in
			*" $key "*) ;;
			*)
				PHP_ERROR="unknown key :: $key"
				return 1
				;;
		esac
		if [ "$key" != 'NAME' ] && [ "$key" != 'BUILTIN' ]; then
			PROFILE[$key]=${BASH_REMATCH[2]}
		fi
	done < "$file"
}

# Load an existing profile into PROFILE
php_profile_load() {
	local line key
	line=$(php_profile_line "$1")
	PROFILE=()
	for key in $PHP_PROFILE_KEYS; do
		PROFILE[$key]=$(php_kv "$line" "$key")
	done
}

# Write PROFILE as the line of NAME (added or replaced) under the store's lock
php_profile_save() {
	local name="$1" builtin="$2" line='' key file tmp
	PROFILE[NAME]=$name
	PROFILE[BUILTIN]=$builtin
	for key in $PHP_PROFILE_KEYS; do
		line="$line$key='${PROFILE[$key]-}' "
	done
	line=${line% }
	file=$(php_profiles_file)
	php_profiles_ensure
	(
		flock -x 9
		tmp=$(mktemp "$file.XXXXXX")
		awk -v name="NAME='$name' " -v line="$line" '
			index($0, name) == 1 { if (!done) print line; done = 1; next }
			{ print }
			END { if (!done) print line }
		' "$file" > "$tmp"
		chmod 644 "$tmp"
		mv -f "$tmp" "$file"
	) 9> "$file.lock"
}

php_profile_remove() {
	local name="$1" file tmp
	file=$(php_profiles_file)
	(
		flock -x 9
		tmp=$(mktemp "$file.XXXXXX")
		grep -vF "NAME='$name' " "$file" > "$tmp"
		chmod 644 "$tmp"
		mv -f "$tmp" "$file"
		if [ "$(php_kv "$(php_profiles_builtin | grep -F "NAME='$name' ")" BUILTIN)" = 'yes' ]; then
			echo "$name" >> "$PHP_DATA/profiles.deleted"
		fi
	) 9> "$file.lock"
}

#----------------------------------------------------------#
#                  Domains and packages                    #
#----------------------------------------------------------#

php_domain_sidecar() {
	echo "$ARCPANEL/data/users/$1/web.php.conf"
}

# The sidecar key of a domain: the domain, or * for the user's shared pool
php_domain_key() {
	if [ "$WEB_BACKEND_POOL" = 'user' ]; then
		echo '*'
	else
		echo "$1"
	fi
}

# The profile of a domain's pool (default without a line)
php_domain_profile() {
	local key line profile
	key=$(php_domain_key "$2")
	line=$(grep -F "DOMAIN='$key' " "$(php_domain_sidecar "$1")" 2> /dev/null | head -n1)
	profile=$(php_kv "$line" PROFILE)
	echo "${profile:-default}"
}

# The sidecar line of a pool key (a domain, or *)
php_domain_line() {
	grep -F "DOMAIN='$2' " "$(php_domain_sidecar "$1")" 2> /dev/null | head -n1
}

# Rewrite the line of a pool key under the sidecar's lock (E19 spec §3.1):
# PROFILE when $3 is not empty, setting column $4 set to $5 (* with an empty
# $5 clears all of them); the other values stay. Only settings with a value
# are written, and the line goes when the profile is default and no setting
# is left.
php_domain_line_update() {
	local user="$1" key="$2" profile="$3" column="$4" value="$5" file tmp line out pair name current any=no
	file=$(php_domain_sidecar "$user")
	(
		flock -x 9
		line=$(grep -F "DOMAIN='$key' " "$file" 2> /dev/null | head -n1)
		if [ -z "$profile" ]; then
			profile=$(php_kv "$line" PROFILE)
		fi
		profile=${profile:-default}
		out="DOMAIN='$key' PROFILE='$profile'"
		for pair in $PHP_DOMAIN_SETTINGS; do
			name=${pair%%:*}
			current=$(php_kv "$line" "$name")
			if [ "$column" = '*' ] || [ "$column" = "$name" ]; then
				current=$value
			fi
			if [ -n "$current" ]; then
				out="$out $name='$current'"
				any=yes
			fi
		done
		tmp=$(mktemp "$file.XXXXXX")
		grep -vF "DOMAIN='$key' " "$file" 2> /dev/null > "$tmp"
		if [ "$profile" != 'default' ] || [ "$any" = 'yes' ]; then
			echo "$out" >> "$tmp"
		fi
		chmod 640 "$tmp"
		mv -f "$tmp" "$file"
	) 9> "$file.lock"
}

# Set the profile of a domain's pool; the settings stay, and default with
# no setting removes the line
php_domain_profile_set() {
	php_domain_line_update "$1" "$2" "$3" '' ''
}

# Remove the line of a domain: its profile and its settings
php_domain_profile_delete() {
	local file tmp
	file=$(php_domain_sidecar "$1")
	(
		flock -x 9
		tmp=$(mktemp "$file.XXXXXX")
		grep -vF "DOMAIN='$2' " "$file" 2> /dev/null > "$tmp"
		chmod 640 "$tmp"
		mv -f "$tmp" "$file"
	) 9> "$file.lock"
}

# Move the line of a domain to its new name
php_domain_line_rename() {
	local file tmp line
	file=$(php_domain_sidecar "$1")
	(
		flock -x 9
		line=$(grep -F "DOMAIN='$2' " "$file" 2> /dev/null | head -n1)
		tmp=$(mktemp "$file.XXXXXX")
		grep -vF -e "DOMAIN='$2' " -e "DOMAIN='$3' " "$file" 2> /dev/null > "$tmp"
		if [ -n "$line" ]; then
			echo "DOMAIN='$3' ${line#"DOMAIN='$2' "}" >> "$tmp"
		fi
		chmod 640 "$tmp"
		mv -f "$tmp" "$file"
	) 9> "$file.lock"
}

#----------------------------------------------------------#
#                Per-domain settings (E19)                 #
#----------------------------------------------------------#

# Sidecar column, directive and package maximum of each setting, in the
# order of the pool lines (E19 spec §1)
PHP_DOMAIN_SETTINGS="MEMORY_LIMIT:memory_limit:PHP_MAX_MEMORY UPLOAD_MAX_FILESIZE:upload_max_filesize:PHP_MAX_UPLOAD POST_MAX_SIZE:post_max_size:PHP_MAX_UPLOAD MAX_EXECUTION_TIME:max_execution_time:PHP_MAX_EXECUTION_TIME MAX_INPUT_VARS:max_input_vars:PHP_MAX_INPUT_VARS DISPLAY_ERRORS:display_errors: DATE_TIMEZONE:date.timezone:"

# Column of a directive, in REPLY with its maximum key in PHP_SETTING_MAX_KEY;
# fails for a directive that is not a setting
php_setting_column() {
	local pair rest
	for pair in $PHP_DOMAIN_SETTINGS; do
		rest=${pair#*:}
		if [ "${rest%%:*}" = "$1" ]; then
			REPLY=${pair%%:*}
			PHP_SETTING_MAX_KEY=${rest#*:}
			return 0
		fi
	done
	return 1
}

# Validate VALUE of a directive (E19 spec §5.1); the error is in PHP_ERROR
php_setting_valid() {
	local directive="$1" value="$2"
	case "$directive" in
		memory_limit | upload_max_filesize | post_max_size)
			if [[ ! "$value" =~ ^[0-9]{1,6}[KMG]?$ ]] || [ "$(php_size_bytes "$value")" -lt 1 ]; then
				PHP_ERROR="$directive must be a size such as 256M"
				return 1
			fi
			;;
		max_execution_time)
			if [[ ! "$value" =~ ^[0-9]{1,5}$ ]] || [ "$value" -gt 86400 ]; then
				PHP_ERROR="max_execution_time must be seconds from 0 to 86400"
				return 1
			fi
			;;
		max_input_vars)
			if [[ ! "$value" =~ ^[0-9]{1,7}$ ]] || [ "$value" -lt 1 ] || [ "$value" -gt 1000000 ]; then
				PHP_ERROR="max_input_vars must be a number from 1 to 1000000"
				return 1
			fi
			;;
		display_errors)
			if [ "$value" != 'on' ] && [ "$value" != 'off' ]; then
				PHP_ERROR="display_errors must be on or off"
				return 1
			fi
			;;
		date.timezone)
			if [[ ! "$value" =~ ^[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+){0,2}$ ]] || [ ! -f "/usr/share/zoneinfo/$value" ]; then
				PHP_ERROR="date.timezone must be a time zone such as Europe/Berlin"
				return 1
			fi
			;;
		*)
			PHP_ERROR="$directive is not a per-domain PHP setting"
			return 1
			;;
	esac
}

# Package maximum of a directive (empty for none), with the default of a
# package that does not set it (D7)
php_setting_max() {
	php_setting_column "$2" || return 0
	[ -n "$PHP_SETTING_MAX_KEY" ] || return 0
	php_pkg_get "$1" "$PHP_SETTING_MAX_KEY"
}

# Whether VALUE of a directive is above MAX (unlimited or empty is no maximum)
php_setting_above() {
	local directive="$1" value="$2" max="$3"
	if [ -z "$max" ] || [ "$max" = 'unlimited' ]; then
		return 1
	fi
	case "$directive" in
		memory_limit | upload_max_filesize | post_max_size)
			[ "$(php_size_bytes "$value")" -gt "$(php_size_bytes "$max")" ]
			;;
		*)
			[ "$value" -gt "$max" ]
			;;
	esac
}

# The pool lines of a pool key's settings, each clamped to the package
# maximum of the user (D9); nothing when it has no setting
php_domain_settings_block() {
	local user="$1" key="$2" line package pair column directive rest value max printed=no
	line=$(php_domain_line "$user" "$key")
	[ -n "$line" ] || return 0
	package=$(php_user_package "$user")
	for pair in $PHP_DOMAIN_SETTINGS; do
		column=${pair%%:*}
		rest=${pair#*:}
		directive=${rest%%:*}
		value=$(php_kv "$line" "$column")
		[ -n "$value" ] || continue
		max=$(php_setting_max "$package" "$directive")
		if php_setting_above "$directive" "$value" "$max"; then
			value=$max
		fi
		if [ "$printed" = 'no' ]; then
			echo '; E19 per-domain settings'
			printed=yes
		fi
		if [ "$directive" = 'display_errors' ]; then
			echo "php_admin_flag[$directive] = $value"
		else
			echo "php_admin_value[$directive] = $value"
		fi
	done
}

# Users whose package is PACKAGE, one per line
php_package_users() {
	grep -lx "PACKAGE='$1'" "$ARCPANEL"/data/users/*/user.conf 2> /dev/null | xargs -r -n1 dirname | xargs -r -n1 basename
}

# Write again the pools of USER whose settings are governed by MAX_KEY (all
# maximums when empty) and above OLD or NEW of it (any value when OLD and
# NEW are empty); print the PHP versions of the pools written
php_settings_rerender() {
	local user="$1" max_key="${2-}" old="${3-}" new="${4-}" line key domain backend pair column rest directive value hit
	[ -f "$(php_domain_sidecar "$user")" ] || return 0
	while IFS= read -r line; do
		key=$(php_kv "$line" DOMAIN)
		hit=no
		for pair in $PHP_DOMAIN_SETTINGS; do
			column=${pair%%:*}
			rest=${pair#*:}
			directive=${rest%%:*}
			[ -n "${rest#*:}" ] || continue
			[ -z "$max_key" ] || [ "${rest#*:}" = "$max_key" ] || continue
			value=$(php_kv "$line" "$column")
			[ -n "$value" ] || continue
			if [ -z "$old$new" ] || php_setting_above "$directive" "$value" "$old" || php_setting_above "$directive" "$value" "$new"; then
				hit=yes
			fi
		done
		[ "$hit" = 'yes' ] || continue
		# In user-pool mode any domain of the user writes the shared pool
		if [ "$key" = '*' ]; then
			domain=$(grep -m1 -o "^DOMAIN='[^']*'" "$ARCPANEL/data/users/$user/web.conf" 2> /dev/null | cut -d\' -f2)
		else
			domain=$key
		fi
		[ -n "$domain" ] || continue
		backend=$(grep -F "DOMAIN='$domain' " "$ARCPANEL/data/users/$user/web.conf" 2> /dev/null | head -n1)
		backend=$(php_kv "$backend" BACKEND)
		backend=${backend:-default}
		[ "$backend" != 'no-php' ] || continue
		"$BIN/v-add-web-domain-backend" "$user" "$domain" "$backend" no > /dev/null 2>&1
		php_backend_version "$backend"
	done < "$(php_domain_sidecar "$user")"
}

# Whether post_max_size would fall below upload_max_filesize when the pool
# key sets both (E19 spec §5.1); the error is in PHP_ERROR. An inherited one
# only warns (php_domain_settings_json).
php_setting_pair_invalid() {
	local line upload post
	line=$(php_domain_line "$1" "$2")
	upload=$(php_kv "$line" UPLOAD_MAX_FILESIZE)
	post=$(php_kv "$line" POST_MAX_SIZE)
	case "$3" in
		upload_max_filesize) upload=$4 ;;
		post_max_size) post=$4 ;;
		*) return 1 ;;
	esac
	if [ -n "$upload" ] && [ -n "$post" ] && [ "$(php_size_bytes "$post")" -lt "$(php_size_bytes "$upload")" ]; then
		PHP_ERROR="post_max_size $post must be at least upload_max_filesize $upload"
		return 0
	fi
	return 1
}

# Write the pool of a domain again and, unless RESTART is no, reload its
# PHP-FPM and check that it still runs
php_settings_apply() {
	local user="$1" domain="$2" backend="$3" restart="$4"
	"$BIN/v-add-web-domain-backend" "$user" "$domain" "$backend" no > /dev/null 2>&1 || return 1
	if [ "$restart" = 'no' ]; then
		return 0
	fi
	php_fpm_reload "$(php_backend_version "$backend")"
}

# The nginx client_max_body_size of a domain: its own configuration, else the
# server's (E19 D10)
php_nginx_max_body() {
	local value
	value=$(grep -hoE '^[[:space:]]*client_max_body_size[[:space:]]+[0-9]+[kKmMgG]?' \
		"$HOMEDIR/$1/conf/web/$2/nginx.conf" "$HOMEDIR/$1/conf/web/$2/nginx.ssl.conf" 2> /dev/null | head -n1)
	if [ -z "$value" ]; then
		value=$(grep -hoE '^[[:space:]]*client_max_body_size[[:space:]]+[0-9]+[kKmMgG]?' /etc/nginx/nginx.conf 2> /dev/null | head -n1)
	fi
	echo "${value##* }"
}

# The settings of a domain's pool as JSON (E19 spec §5): POOL, SUPPORTED,
# PACKAGE, NGINX_MAX_BODY, SETTINGS with VALUE, MAX, INI, PROFILE,
# EFFECTIVE and SOURCE (php.ini, profile, domain or capped) per directive,
# and WARNINGS
php_domain_settings_json() {
	local user="$1" domain="$2" backend="${3:-default}"
	local key line package profile version='' ini='{}' supported=no pool=domain nginx json='{}' warnings=()
	local pair column rest directive value max ini_value profile_value effective origin
	local -A eff=() origins=()
	key=$(php_domain_key "$domain")
	if [ "$key" = '*' ]; then
		pool=user
	fi
	line=$(php_domain_line "$user" "$key")
	package=$(php_user_package "$user")
	profile=$(php_domain_profile "$user" "$domain")
	if [ -n "$WEB_BACKEND" ] && [ "$backend" != 'no-php' ]; then
		version=$(php_backend_version "$backend")
		if php_template_has_profile "$PHP_FPM_TPL_DIR/$backend.tpl"; then
			supported=yes
		fi
		if php_version_installed "$version"; then
			ini=$(php_ini_helper "$version" values "$(php_ini_file "$version" fpm)" fpm 2> /dev/null)
		fi
	fi
	for pair in $PHP_DOMAIN_SETTINGS; do
		column=${pair%%:*}
		rest=${pair#*:}
		directive=${rest%%:*}
		value=$(php_kv "$line" "$column")
		max=$(php_setting_max "$package" "$directive")
		ini_value=$(jq -r --arg d "$directive" '.[$d].VALUE // ""' <<< "${ini:-"{}"}")
		profile_value=''
		if [[ " $PHP_PROFILE_LIMITS " == *" $column:"* ]]; then
			profile_value=$(php_profile_get "$profile" "$column")
		fi
		if [ "$supported" = 'yes' ] && [ -n "$value" ]; then
			effective=$value
			origin=domain
			if php_setting_above "$directive" "$value" "$max"; then
				effective=$max
				origin=capped
				warnings+=("$directive $value is above the package maximum $max and applies as $max")
			fi
		elif [ "$supported" = 'yes' ] && [ -n "$profile_value" ]; then
			effective=$profile_value
			origin=profile
		else
			effective=$ini_value
			origin=php.ini
		fi
		eff["$directive"]=$effective
		origins["$directive"]=$origin
		json=$(jq -c --arg d "$directive" --arg v "$value" --arg m "$max" --arg i "$ini_value" --arg p "$profile_value" \
			--arg e "$effective" --arg s "$origin" \
			'. + {($d): {VALUE: $v, MAX: $m, INI: $i, PROFILE: $p, EFFECTIVE: $e, SOURCE: $s}}' <<< "$json")
	done

	local memory post upload body
	memory=$(php_size_bytes "${eff[memory_limit]}")
	post=$(php_size_bytes "${eff[post_max_size]}")
	upload=$(php_size_bytes "${eff[upload_max_filesize]}")
	if [ -n "$memory" ] && [ -n "$post" ] && [ "$memory" -gt 0 ] && [ "$post" -gt 0 ] && [ "$memory" -lt "$post" ]; then
		warnings+=("memory_limit ${eff[memory_limit]} is below post_max_size ${eff[post_max_size]}")
	fi
	if [ -n "$post" ] && [ -n "$upload" ] && [ "$post" -gt 0 ] && [ "$post" -lt "$upload" ]; then
		warnings+=("post_max_size ${eff[post_max_size]} (${origins[post_max_size]}) is below upload_max_filesize ${eff[upload_max_filesize]}, which limits uploads to ${eff[post_max_size]}")
	fi
	nginx=$(php_nginx_max_body "$user" "$domain")
	body=$(php_size_bytes "$nginx")
	if [ -n "$body" ] && [ -n "$upload" ] && [ "$body" -gt 0 ] && [ "$upload" -gt "$body" ]; then
		warnings+=("upload_max_filesize ${eff[upload_max_filesize]} is above the nginx client_max_body_size $nginx of the domain, which stops larger uploads")
	fi

	jq -n --arg d "$domain" --arg pool "$pool" --arg s "$supported" --arg pk "$package" --arg pr "$profile" \
		--arg v "$version" --arg n "$nginx" --argjson settings "$json" \
		--argjson w "$(printf '%s\n' "${warnings[@]}" | jq -Rn '[inputs | select(length > 0)]')" \
		'{DOMAIN: $d, POOL: $pool, SUPPORTED: $s, PACKAGE: $pk, PROFILE: $pr, VERSION: $v,
			NGINX_MAX_BODY: $n, SETTINGS: $settings, WARNINGS: $w}'
}

# Settings of a backed-up sidecar LINE for a restored domain: every valid
# value is kept, also one above the package maximum, which the pool clamps
php_domain_settings_restore() {
	local user="$1" key="$2" line="$3" pair column rest directive value
	for pair in $PHP_DOMAIN_SETTINGS; do
		column=${pair%%:*}
		rest=${pair#*:}
		directive=${rest%%:*}
		value=$(php_kv "$line" "$column")
		if [ -n "$value" ] && php_setting_valid "$directive" "$value"; then
			php_domain_line_update "$user" "$key" '' "$column" "$value"
		fi
	done
}

# Web domains with their pool's profile: "USER DOMAIN BACKEND PROFILE" lines;
# in user-pool mode the profile of the shared pool
php_domain_pools() {
	local conf user line domain backend
	for conf in "$ARCPANEL"/data/users/*/web.conf; do
		[ -f "$conf" ] || continue
		user=$(basename "$(dirname "$conf")")
		while IFS= read -r line; do
			domain=$(php_kv "$line" DOMAIN)
			backend=$(php_kv "$line" BACKEND)
			[ -n "$domain" ] || continue
			echo "$user $domain ${backend:-default} $(php_domain_profile "$user" "$domain")"
		done < "$conf"
	done
}

# Domains whose pool uses a profile: "USER DOMAIN BACKEND" lines (one domain
# per user in user-pool mode, which rewrites the shared pool)
php_profile_domains() {
	local user domain backend profile seen=' '
	while read -r user domain backend profile; do
		[ "$profile" = "$1" ] || continue
		[ "$backend" != 'no-php' ] || continue
		if [ "$WEB_BACKEND_POOL" = 'user' ]; then
			[[ "$seen" != *" $user "* ]] || continue
			seen="$seen$user "
		fi
		echo "$user $domain $backend"
	done < <(php_domain_pools)
}

# Count of domains per form directive that their profile overrides, for
# the php.ini page of a version (§6.4), as JSON
php_profile_overrides() {
	local version="$1" user domain backend profile pair key directive
	local -A count=() has=()
	for profile in $(php_profile_names); do
		for pair in $PHP_PROFILE_LIMITS; do
			key=${pair%%:*}
			if [ -n "$(php_profile_get "$profile" "$key")" ]; then
				has["$profile:${pair#*:}"]=1
			fi
		done
	done
	while read -r user domain backend profile; do
		[ "$backend" != 'no-php' ] || continue
		[ "$(php_backend_version "$backend")" = "$version" ] || continue
		php_template_has_profile "$PHP_FPM_TPL_DIR/$backend.tpl" || continue
		for pair in $PHP_PROFILE_LIMITS; do
			directive=${pair#*:}
			if [ -n "${has["$profile:$directive"]-}" ]; then
				count[$directive]=$((${count[$directive]:-0} + 1))
			fi
		done
	done < <(php_domain_pools)
	for directive in "${!count[@]}"; do
		echo "$directive ${count[$directive]}"
	done | jq -Rn '[inputs | split(" ") | {(.[0]): (.[1] | tonumber)}] | add // {}'
}

# The package of a user
php_user_package() {
	grep -m1 "^PACKAGE='" "$ARCPANEL/data/users/$1/user.conf" 2> /dev/null | cut -d\' -f2
}

# A PHP key of a package sidecar with its default (§7.5)
php_pkg_get() {
	local value
	value=$(grep -m1 "^$2='" "$ARCPANEL/data/packages/$1.tenancy" 2> /dev/null | cut -d\' -f2)
	if [ -z "$value" ]; then
		case "$2" in
			PHP_VERSIONS) value='*' ;;
			PHP_PROFILES | PHP_DEFAULT_PROFILE) value='default' ;;
			# Maximums of the per-domain settings (E19 D7)
			PHP_MAX_MEMORY) value='512M' ;;
			PHP_MAX_UPLOAD) value='256M' ;;
			PHP_MAX_EXECUTION_TIME) value='300' ;;
			PHP_MAX_INPUT_VARS) value='10000' ;;
		esac
	fi
	echo "$value"
}

# Whether a package allows a version: * means every installed version that
# has not reached its end of life; a list names versions exactly
php_pkg_allows_version() {
	local list
	list=$(php_pkg_get "$1" PHP_VERSIONS)
	if [ "$list" = '*' ]; then
		php_version_installed "$2" && [ "$(php_version_status "$2")" != 'eol' ]
	else
		[[ ",$list," == *",$2,"* ]]
	fi
}

php_pkg_allows_profile() {
	local list
	list=$(php_pkg_get "$1" PHP_PROFILES)
	if [ "$list" = '*' ]; then
		php_profile_exists "$2"
	else
		[[ ",$list," == *",$2,"* ]]
	fi
}

# Whether the package of USER allows backend TEMPLATE: a template that names
# a version (PHP-X_Y, also at the end of a custom name) only for a version
# the package allows; any other template is not about the PHP version. The
# domain's CURRENT template stays allowed (D11). The panel's
# PhpManager::templateAllowed answers the same (E39 W5 P5).
php_backend_allowed_for_user() {
	local user="$1" template="$2" current="${3-}"
	if [ -n "$current" ] && [ "$template" = "$current" ]; then
		return 0
	fi
	[[ "$template" =~ PHP-([0-9]{1,2})_([0-9]{1,2})$ ]] || return 0
	php_pkg_allows_version "$(php_user_package "$user")" "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
}

# The profile a restored domain gets: PROFILE when it exists and the
# package of USER allows it, else the package's default profile (E39 W5 P3)
php_restore_profile() {
	local user="$1" profile="$2" package
	package=$(php_user_package "$user")
	if php_profile_exists "$profile" && php_pkg_allows_profile "$package" "$profile"; then
		echo "$profile"
	else
		php_pkg_get "$package" PHP_DEFAULT_PROFILE
	fi
}

# Installed versions a package allows, one per line
php_pkg_versions() {
	local version
	for version in $(php_installed_versions); do
		if php_pkg_allows_version "$1" "$version"; then
			echo "$version"
		fi
	done
}

# Profiles a package allows, one per line
php_pkg_profiles() {
	local profile
	for profile in $(php_profile_names); do
		if php_pkg_allows_profile "$1" "$profile"; then
			echo "$profile"
		fi
	done
}

# Profiles as a JSON object keyed by name, with DOMAINS (pools using each,
# from the "USER DOMAIN BACKEND PROFILE" lines of php_domain_pools in $1) and
# PACKAGES (packages allowing each); only NAME when $2 is set
php_profiles_json() {
	local pools="$1" only="$2" name line key packages package count json='{}' args
	for name in $(php_profile_names); do
		if [ -n "$only" ] && [ "$name" != "$only" ]; then
			continue
		fi
		line=$(php_profile_line "$name")
		count=$(awk -v p="$name" '$4 == p && $3 != "no-php"' <<< "$pools" | grep -c .)
		packages=''
		for package in "$ARCPANEL"/data/packages/*.pkg; do
			[ -f "$package" ] || continue
			package=$(basename "$package" .pkg)
			if php_pkg_allows_profile "$package" "$name"; then
				packages="$packages,$package"
			fi
		done
		args=()
		for key in $PHP_PROFILE_KEYS; do
			args+=(--arg "$key" "$(php_kv "$line" "$key")")
		done
		json=$(jq -c "${args[@]}" --arg n "$name" --arg d "$count" --arg p "${packages#,}" \
			'. + {($n): {NAME: $NAME, DESCRIPTION: $DESCRIPTION, PM: $PM, MAX_CHILDREN: $MAX_CHILDREN,
				START_SERVERS: $START_SERVERS, MIN_SPARE: $MIN_SPARE, MAX_SPARE: $MAX_SPARE,
				MAX_REQUESTS: $MAX_REQUESTS, IDLE_TIMEOUT: $IDLE_TIMEOUT,
				REQUEST_TERMINATE_TIMEOUT: $REQUEST_TERMINATE_TIMEOUT, MEMORY_LIMIT: $MEMORY_LIMIT,
				UPLOAD_MAX_FILESIZE: $UPLOAD_MAX_FILESIZE, POST_MAX_SIZE: $POST_MAX_SIZE,
				MAX_EXECUTION_TIME: $MAX_EXECUTION_TIME, MAX_INPUT_VARS: $MAX_INPUT_VARS,
				BUILTIN: $BUILTIN, DOMAINS: $d, PACKAGES: $p}}' <<< "$json")
	done
	echo "$json"
}
