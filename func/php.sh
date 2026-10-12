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

# Value of KEY in a KEY='value' line
php_kv() {
	grep -oE "(^| )$2='[^']*'" <<< "$1" | head -n1 | cut -d\' -f2
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

# Versions of the catalog, one per line, oldest first
php_catalog_versions() {
	grep -oE "^VERSION='[0-9]{1,2}\.[0-9]{1,2}'" "$PHP_CATALOG" 2> /dev/null | cut -d\' -f2 | sort -V
}

# The catalog line of a version (exact match)
php_catalog_line() {
	grep -F "VERSION='$1' " "$PHP_CATALOG" 2> /dev/null | grep -E "^VERSION=" | head -n1
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
	php_version_format_valid "$1" && php_catalog_versions | grep -qxF "$1"
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
}

# Look up the install candidates of packages that the cache does not hold or
# holds for longer than ten minutes, in one apt-cache call
php_apt_refresh() {
	local file now pkg line time out current tmp
	local -a missing=()
	local -A found=()
	file=$(php_apt_cache_file)
	now=$(date +%s)
	for pkg in "$@"; do
		line=$(grep -F "PACKAGE='$pkg' " "$file" 2> /dev/null | tail -n1)
		time=$(php_kv "$line" TIME)
		if [ -z "$line" ] || [ $((now - ${time:-0})) -ge "$PHP_APT_CACHE_TTL" ]; then
			missing+=("$pkg")
		fi
	done
	if [ "${#missing[@]}" -eq 0 ]; then
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
}

# Whether a package has an install candidate in the configured repositories
php_apt_candidate() {
	local line
	php_apt_refresh "$1"
	line=$(grep -F "PACKAGE='$1' " "$(php_apt_cache_file)" 2> /dev/null | tail -n1)
	[ "$(php_kv "$line" CANDIDATE)" = 'yes' ]
}

# Whether the PHP repository of the server provides a version (phpX.Y-fpm)
php_version_available() {
	php_apt_candidate "php$1-fpm"
}

# Installed Debian packages among the arguments, one per line
php_dpkg_installed() {
	dpkg-query -W -f='${Package} ${db:Status-Status}\n' "$@" 2> /dev/null | awk '$2 == "installed" { print $1 }'
}

# Whether version A <= version B
php_version_le() {
	[ "$(printf '%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]
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
	out=$("php-fpm$version" "${args[@]}" 2>&1)
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

# The catalog line of an extension (exact match)
php_ext_line() {
	php_ext_lines | grep -F "NAME='$1' " | head -n1
}

php_ext_name_valid() {
	[[ "$1" =~ ^[a-z0-9_]{1,32}$ ]] && [ -n "$(php_ext_line "$1")" ]
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
	for name in $(php_ext_lines | cut -d\' -f2); do
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
		module=$(basename "$file" .ini)
		module=${module#*-}
		if [[ "$module" =~ ^[A-Za-z0-9_]+$ ]] && [ -f "/etc/php/$1/mods-available/$module.ini" ]; then
			echo "$module"
		fi
	done | sort -u
}

php_mod_enabled() {
	php_mods_enabled "$1" "$2" | grep -qxF "$3"
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

	# A syntax error stops here: PHP would not read the rest
	if ! jq -e 'any(.[]; .level == "error" and .directive == "")' "$dir/semantic.json" > /dev/null; then
		# Unknown directives, with the extensions the candidate loads
		PHP_INI_SCAN_DIR="$scan" "php$version" -c "$file" -d display_startup_errors=0 -d display_errors=0 \
			-d log_errors=0 -d disable_functions= -d disable_classes= -d open_basedir= \
			-d auto_prepend_file= -d auto_append_file= "$PHP_INI_HELPER" known > "$dir/known.json" 2> /dev/null
		if jq -e 'type == "array"' "$dir/known.json" > /dev/null 2>&1; then
			php_ini_helper "$version" unknown "$file" "$dir/known.json" "$(php_ini_file "$version" "$sapi")" > "$dir/unknown.json" 2> /dev/null \
				&& parts+=("$dir/unknown.json")
		fi

		# 3. PHP starts with the candidate: no startup warning (an extension
		# that does not load); deprecations are warnings
		out=$(PHP_INI_SCAN_DIR="$scan" "php$version" -c "$file" -d display_startup_errors=1 \
			-d display_errors=stderr -d log_errors=0 -d auto_prepend_file= -d auto_append_file= \
			-r 'exit(0);' 2>&1)
		echo '[]' > "$dir/start.json"
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

		if [ "$sapi" = 'fpm' ] && ! jq -e 'any(.[]; .level == "error")' "$dir/start.json" > /dev/null; then
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
	local dir
	dir=$(php_ini_backup_dir "$1" "$2")
	ls -1 "$dir" 2> /dev/null | grep -E '^[0-9]{8}-[0-9]{6}(-[0-9]+)?\.ini$' | sort -V \
		| head -n "-$PHP_INI_KEEP" | while read -r old; do
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
