#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel tenancy functions: resellers and the customers they own (E11)    #
#                                                                           #
# Upstream commands rewrite user.conf and *.pkg with a fixed list of keys,  #
# so tenancy data lives in sidecar files that they never touch:             #
#   $ARCPANEL/data/users/<user>/tenancy.conf     OWNER, R_* (resellers)       #
#   $ARCPANEL/data/packages/<package>.tenancy    USERS, RESELLER_PACKAGES,    #
#                                              RESELLER_VISIBLE,            #
#                                              RESELLER_OWNER, PORTS (E12), #
#                                              NODE, NODE_VERSIONS,         #
#                                              NODE_ALLOWED (E13),          #
#                                              NODE_APPS (E14), DOCKER,     #
#                                              DOCKER_APPS, DOCKER_CPUS,    #
#                                              DOCKER_MEMORY (E4),          #
#                                              PHP_VERSIONS, PHP_PROFILES,  #
#                                              PHP_DEFAULT_PROFILE (E41)    #
# See docs/features/specs/2026-10-05-003-e11-reseller-design.md.            #
#                                                                           #
#===========================================================================#

# Package limits that are totals over a reseller's tree (spec D7), and the
# user.conf counter that holds the reseller's own usage of each.
TENANCY_QUOTA_KEYS="WEB_DOMAINS DNS_DOMAINS MAIL_DOMAINS MAIL_ACCOUNTS DATABASES CRON_JOBS DISK_QUOTA BANDWIDTH"

# Package limits a reseller-created package may not raise above the
# reseller's own (spec D8, E39 W4 spec §8.3)
TENANCY_PACKAGE_LIMIT_KEYS="WEB_DOMAINS WEB_ALIASES DNS_DOMAINS DNS_RECORDS MAIL_DOMAINS MAIL_ACCOUNTS RATE_LIMIT DATABASES CRON_JOBS DISK_QUOTA CPU_QUOTA CPU_QUOTA_PERIOD MEMORY_LIMIT SWAP_LIMIT BANDWIDTH BACKUPS BACKUPS_INCREMENTAL"

# Sidecar keys of a reseller-created package compared with the reseller's
# own package (E39 W4 spec §8.3); PHP_DEFAULT_PROFILE is checked apart
TENANCY_PACKAGE_TENANCY_LIMIT_KEYS="PORTS NODE NODE_VERSIONS NODE_ALLOWED NODE_APPS DOCKER DOCKER_APPS DOCKER_CPUS DOCKER_MEMORY PHP_VERSIONS PHP_PROFILES"

tenancy_usage_key() {
	case "$1" in
		DISK_QUOTA) echo "U_DISK" ;;
		*) echo "U_$1" ;;
	esac
}

#----------------------------------------------------------#
#                 Sidecar file primitives                  #
#----------------------------------------------------------#

tenancy_user_file() {
	echo "$ARCPANEL/data/users/$1/tenancy.conf"
}

tenancy_package_file() {
	echo "$ARCPANEL/data/packages/$1.tenancy"
}

# Value of KEY in FILE, empty when the file or the key is missing
tenancy_conf_get() {
	if [ -f "$1" ]; then
		grep -m1 "^$2='" "$1" | cut -f 2 -d \'
	fi
}

# Set KEY to VALUE in FILE (created when missing). Values never contain
# quotes or line breaks: callers validate them first.
tenancy_conf_set() {
	local file="$1" key="$2" value="$3"
	if [[ "$value" == *"'"* || "$value" == *$'\n'* ]]; then
		return "$E_INVALID"
	fi
	if [ ! -f "$file" ]; then
		touch "$file"
	fi
	if grep -q "^$key='" "$file"; then
		local escaped
		escaped=$(printf '%s' "$value" | sed -e 's/[\\&|]/\\&/g')
		sed -i "s|^$key='.*'\$|$key='$escaped'|" "$file"
	else
		echo "$key='$value'" >> "$file"
	fi
}

# Escape a value for a JSON string
tenancy_json_escape() {
	tenancy_json_escape_reply "$1"
	printf '%s' "$REPLY"
}

# Same as tenancy_json_escape, into REPLY instead of standard output
tenancy_json_escape_reply() {
	local s="$1"
	s="${s//\\/\\\\}"
	s="${s//\"/\\\"}"
	s="${s//$'\t'/\\t}"
	s="${s//$'\r'/}"
	s="${s//$'\n'/\\n}"
	REPLY=$s
}

#----------------------------------------------------------#
#                     Users and owners                     #
#----------------------------------------------------------#

# Every panel user, one per line
tenancy_all_users() {
	local dir
	for dir in "$ARCPANEL"/data/users/*/; do
		dir="${dir%/}"
		if [ -f "$dir/user.conf" ]; then
			basename "$dir"
		fi
	done
}

tenancy_user_value() {
	grep -m1 "^$2='" "$ARCPANEL/data/users/$1/user.conf" 2> /dev/null | cut -f 2 -d \'
}

tenancy_role() {
	tenancy_user_value "$1" ROLE
}

tenancy_is_reseller() {
	[ -n "$1" ] && [ -f "$ARCPANEL/data/users/$1/user.conf" ] && [ "$(tenancy_role "$1")" = 'reseller' ]
}

# OWNER as written in the sidecar, without any resolution
tenancy_raw_owner() {
	tenancy_conf_get "$(tenancy_user_file "$1")" OWNER
}

# Resolved owner: admins own themselves (empty), resellers and system
# accounts belong to ROOT_USER, a user belongs to the reseller named in its
# sidecar when that reseller still exists, else to ROOT_USER.
tenancy_get_owner() {
	local user="$1" role owner
	role=$(tenancy_role "$user")
	if [ "$role" = 'admin' ]; then
		echo ""
		return
	fi
	if [ "$role" != 'user' ]; then
		echo "$ROOT_USER"
		return
	fi
	owner=$(tenancy_raw_owner "$user")
	if [ -n "$owner" ] && [ "$owner" != "$ROOT_USER" ] && tenancy_is_reseller "$owner"; then
		echo "$owner"
	else
		echo "$ROOT_USER"
	fi
}

tenancy_set_owner() {
	local file
	file=$(tenancy_user_file "$1")
	tenancy_conf_set "$file" OWNER "$2" || return $?
	chmod 640 "$file"
}

# Customers of a reseller, one per line
tenancy_customers() {
	local reseller="$1" user
	if ! tenancy_is_reseller "$reseller"; then
		return
	fi
	while read -r user; do
		if [ "$(tenancy_role "$user")" = 'user' ] && [ "$(tenancy_raw_owner "$user")" = "$reseller" ]; then
			echo "$user"
		fi
	done < <(tenancy_all_users)
}

#----------------------------------------------------------#
#                         Packages                         #
#----------------------------------------------------------#

# Default of a tenancy key of a package (empty when it has none), in REPLY;
# no subshell, so loops over many keys stay cheap
tenancy_pkg_default() {
	case "$1" in
		USERS | PORTS | DOCKER_APPS) REPLY='0' ;;
		RESELLER_VISIBLE | NODE | DOCKER) REPLY='no' ;;
		DOCKER_CPUS | DOCKER_MEMORY) REPLY='unlimited' ;;
		NODE_VERSIONS | NODE_APPS) REPLY='3' ;;
		NODE_ALLOWED) REPLY='lts' ;;
		PHP_VERSIONS) REPLY='*' ;;
		PHP_PROFILES | PHP_DEFAULT_PROFILE) REPLY='default' ;;
		*) REPLY='' ;;
	esac
}

# Tenancy value of a package with its default
tenancy_pkg_get() {
	local value
	value=$(tenancy_conf_get "$(tenancy_package_file "$1")" "$2")
	if [ -z "$value" ]; then
		tenancy_pkg_default "$2"
		value=$REPLY
	fi
	echo "$value"
}

tenancy_pkg_set() {
	local file
	file=$(tenancy_package_file "$1")
	tenancy_conf_set "$file" "$2" "$3" || return $?
	chmod 644 "$file"
}

# Size like 512, 512M or 2G in MB; empty for unlimited (DOCKER_MEMORY)
tenancy_mb() {
	if [[ "$1" =~ ^([0-9]+)([MG]?)$ ]]; then
		if [ "${BASH_REMATCH[2]}" = 'G' ]; then
			echo $((BASH_REMATCH[1] * 1024))
		else
			echo "${BASH_REMATCH[1]}"
		fi
	fi
}

# Value of a limit in a package file
tenancy_pkg_limit() {
	grep -m1 "^$2='" "$ARCPANEL/data/packages/$1.pkg" 2> /dev/null | cut -f 2 -d \'
}

tenancy_all_packages() {
	local file
	for file in "$ARCPANEL"/data/packages/*.pkg; do
		if [ -f "$file" ]; then
			basename "$file" .pkg
		fi
	done
}

# Validate a tenancy value of a package
tenancy_pkg_value_valid() {
	local key="$1" value="$2" item
	case "$key" in
		USERS | PORTS)
			[ "$value" = 'unlimited' ] || [[ "$value" =~ ^[0-9]{1,5}$ ]]
			;;
		RESELLER_VISIBLE | NODE | DOCKER)
			[ "$value" = 'yes' ] || [ "$value" = 'no' ]
			;;
		NODE_VERSIONS | NODE_APPS | DOCKER_APPS)
			[ "$value" = 'unlimited' ] || [[ "$value" =~ ^[0-9]{1,3}$ ]]
			;;
		DOCKER_CPUS)
			[ "$value" = 'unlimited' ] || [[ "$value" =~ ^[0-9]{1,3}(\.[0-9]{1,2})?$ ]]
			;;
		DOCKER_MEMORY)
			[ "$value" = 'unlimited' ] || [[ "$value" =~ ^[0-9]{1,7}[MG]?$ ]]
			;;
		NODE_ALLOWED)
			[ "$value" = 'lts' ] || [ "$value" = '*' ] || [[ "$value" =~ ^[0-9]{1,3}(,[0-9]{1,3})*$ ]]
			;;
		PHP_VERSIONS)
			[ "$value" = '*' ] || [[ "$value" =~ ^[0-9]{1,2}\.[0-9]{1,2}(,[0-9]{1,2}\.[0-9]{1,2})*$ ]]
			;;
		PHP_PROFILES)
			[ "$value" = '*' ] || [[ "$value" =~ ^[a-z0-9][a-z0-9-]{0,31}(,[a-z0-9][a-z0-9-]{0,31})*$ ]]
			;;
		PHP_DEFAULT_PROFILE)
			[[ "$value" =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]]
			;;
		RESELLER_PACKAGES)
			if [ -z "$value" ] || [ "$value" = '*' ]; then
				return 0
			fi
			for item in ${value//,/ }; do
				[[ "$item" =~ ^[[:alnum:]][-|\.|_[:alnum:]]{0,64}[[:alnum:]]$ ]] || return 1
			done
			;;
		RESELLER_OWNER)
			[ -z "$value" ] || tenancy_is_reseller "$value"
			;;
		*)
			return 1
			;;
	esac
}

# Packages a reseller may assign to its customers, one per line
tenancy_assignable_packages() {
	local reseller="$1" own_package allowed package owner
	own_package=$(tenancy_user_value "$reseller" PACKAGE)
	allowed=$(tenancy_pkg_get "$own_package" RESELLER_PACKAGES)
	while read -r package; do
		if [ "$package" = 'system' ]; then
			continue
		fi
		owner=$(tenancy_pkg_get "$package" RESELLER_OWNER)
		if [ -n "$owner" ]; then
			if [ "$owner" = "$reseller" ]; then
				echo "$package"
			fi
			continue
		fi
		if [ "$allowed" = '*' ]; then
			if [ "$(tenancy_pkg_get "$package" RESELLER_VISIBLE)" = 'yes' ]; then
				echo "$package"
			fi
		elif [[ ",$allowed," == *",$package,"* ]]; then
			echo "$package"
		fi
	done < <(tenancy_all_packages)
}

tenancy_is_assignable() {
	tenancy_assignable_packages "$1" | grep -qx -- "$2"
}

#----------------------------------------------------------#
#                          Quota                           #
#----------------------------------------------------------#

# Soft nested quota (ADR-0004, spec D7). Prints the first exceeded key and
# returns E_LIMIT, or returns 0 when a customer on PACKAGE fits into the
# reseller's plan. With EXCEPT, that customer's current allocation is left
# out (package change of an existing customer).
tenancy_check_quota() {
	local reseller="$1" package="$2" except="$3"
	local customers=() customer users_limit count key limit new used value usage_key

	while read -r customer; do
		if [ -n "$customer" ] && [ "$customer" != "$except" ]; then
			customers+=("$customer")
		fi
	done < <(tenancy_customers "$reseller")

	users_limit=$(tenancy_pkg_get "$(tenancy_user_value "$reseller" PACKAGE)" USERS)
	if [ "$users_limit" != 'unlimited' ]; then
		count=$((${#customers[@]} + 1))
		if [ "$count" -gt "${users_limit:-0}" ]; then
			echo "USERS"
			return "$E_LIMIT"
		fi
	fi

	for key in $TENANCY_QUOTA_KEYS; do
		limit=$(tenancy_user_value "$reseller" "$key")
		if [ -z "$limit" ] || [ "$limit" = 'unlimited' ]; then
			continue
		fi
		new=$(tenancy_pkg_limit "$package" "$key")
		if [ "$new" = 'unlimited' ]; then
			echo "$key"
			return "$E_LIMIT"
		fi
		usage_key=$(tenancy_usage_key "$key")
		used=$(tenancy_user_value "$reseller" "$usage_key")
		used=$((${used:-0} + ${new:-0}))
		for customer in "${customers[@]}"; do
			value=$(tenancy_user_value "$customer" "$key")
			if [ "$value" = 'unlimited' ]; then
				echo "$key"
				return "$E_LIMIT"
			fi
			used=$((used + ${value:-0}))
		done
		if [ "$used" -gt "$limit" ]; then
			echo "$key"
			return "$E_LIMIT"
		fi
	done

	# Ports of the package (E12)
	if ! declare -F ports_quota_check > /dev/null && [ -f "$ARCPANEL/func/ports.sh" ]; then
		# shellcheck source=/usr/local/arcpanel/func/ports.sh
		source "$ARCPANEL/func/ports.sh"
	fi
	if declare -F ports_quota_check > /dev/null; then
		ports_quota_check "$reseller" "$package" "$except" || return $?
	fi
	return 0
}

# A limit in one base unit per key (E39 W4 spec §8.2), in REPLY: bytes for
# MEMORY_LIMIT and SWAP_LIMIT (K, M, G, T are powers of 1024), the same for
# DOCKER_MEMORY with a plain number in MB, hundredths of a CPU for
# DOCKER_CPUS, percent for CPU_QUOTA, milliseconds for CPU_QUOTA_PERIOD, 1
# and 0 for yes and no, and the plain integer for the counted limits;
# "unlimited" stays. Returns 1 when VALUE does not parse. The panel's
# PackageLimits::normalize() follows the same table.
tenancy_limit_value() {
	local key="$1" value="$2" digits unit shift max
	REPLY=''
	if [ "$value" = 'unlimited' ]; then
		REPLY='unlimited'
		return 0
	fi
	case "$key" in
		MEMORY_LIMIT | SWAP_LIMIT | DOCKER_MEMORY)
			[[ "$value" =~ ^([0-9]+)([KMGT]?)$ ]] || return 1
			digits=${BASH_REMATCH[1]}
			unit=${BASH_REMATCH[2]}
			if [ -z "$unit" ] && [ "$key" = 'DOCKER_MEMORY' ]; then
				unit='M'
			fi
			# Digits allowed per unit keep the bytes below 2^63
			case "$unit" in
				K) shift=10 max=15 ;;
				M) shift=20 max=12 ;;
				G) shift=30 max=9 ;;
				T) shift=40 max=6 ;;
				*) shift=0 max=18 ;;
			esac
			[ "${#digits}" -le "$max" ] || return 1
			REPLY=$((10#$digits * (1 << shift)))
			;;
		DOCKER_CPUS)
			[[ "$value" =~ ^([0-9]{1,9})(\.([0-9]{1,2}))?$ ]] || return 1
			unit="${BASH_REMATCH[3]}00"
			REPLY=$((10#${BASH_REMATCH[1]} * 100 + 10#${unit:0:2}))
			;;
		CPU_QUOTA)
			[[ "$value" =~ ^([0-9]{1,9})%$ ]] || return 1
			REPLY=$((10#${BASH_REMATCH[1]}))
			;;
		CPU_QUOTA_PERIOD)
			[[ "$value" =~ ^([0-9]{1,9})(ms|s)$ ]] || return 1
			REPLY=$((10#${BASH_REMATCH[1]}))
			if [ "${BASH_REMATCH[2]}" = 's' ]; then
				REPLY=$((REPLY * 1000))
			fi
			;;
		BACKUPS_INCREMENTAL | NODE | DOCKER)
			case "$value" in
				yes) REPLY=1 ;;
				no) REPLY=0 ;;
				*) return 1 ;;
			esac
			;;
		*)
			[[ "$value" =~ ^[0-9]{1,18}$ ]] || return 1
			REPLY=$((10#$value))
			;;
	esac
	return 0
}

# Whether VALUE of KEY is above LIMIT (E39 W4 spec §8.2): unlimited is the
# largest value, and a value or a limit that does not parse counts as above
tenancy_limit_above() {
	local key="$1" value limit
	tenancy_limit_value "$key" "$2" || return 0
	value=$REPLY
	tenancy_limit_value "$key" "$3" || return 0
	limit=$REPLY
	if [ "$limit" = 'unlimited' ]; then
		return 1
	fi
	[ "$value" = 'unlimited' ] || [ "$value" -gt "$limit" ]
}

# Whether a comma list VALUE allows something that LIMIT does not (NODE_ALLOWED,
# PHP_VERSIONS, PHP_PROFILES): * allows anything; for NODE_ALLOWED, lts is
# within lts and * only
tenancy_list_above() {
	local key="$1" value="$2" limit="$3" item
	if [ "$limit" = '*' ]; then
		return 1
	fi
	if [ "$value" = '*' ]; then
		return 0
	fi
	if [ "$key" = 'NODE_ALLOWED' ] && { [ "$value" = 'lts' ] || [ "$limit" = 'lts' ]; }; then
		[ "$value" != "$limit" ]
		return
	fi
	for item in ${value//,/ }; do
		if [[ ",$limit," != *",$item,"* ]]; then
			return 0
		fi
	done
	return 1
}

# Whether the sidecar VALUE of KEY of a reseller's package is above LIMIT,
# the reseller's own value (E39 W4 spec §8.3)
tenancy_pkg_tenancy_above() {
	case "$1" in
		NODE_ALLOWED | PHP_VERSIONS | PHP_PROFILES) tenancy_list_above "$@" ;;
		*) tenancy_limit_above "$@" ;;
	esac
}

# Every key of the package FILE of RESELLER that its plan does not allow,
# one per line (E39 W4 spec §8): a limit above the reseller's own limit, a
# shell outside POLICY_RESELLER_PACKAGE_SHELLS (unless CURRENT, the package
# file it replaces, holds it), and templates other than the reseller's own
# while users may not choose them. Returns E_LIMIT when it printed a key.
tenancy_package_excess() {
	local reseller="$1" file="$2" current="$3" key limit value shells found=0
	for key in $TENANCY_PACKAGE_LIMIT_KEYS; do
		limit=$(tenancy_user_value "$reseller" "$key")
		if [ -z "$limit" ] || [ "$limit" = 'unlimited' ]; then
			continue
		fi
		value=$(grep -m1 "^$key='" "$file" | cut -f 2 -d \')
		if [ -n "$value" ] && tenancy_limit_above "$key" "$value" "$limit"; then
			echo "$key"
			found=1
		fi
	done

	value=$(grep -m1 "^SHELL='" "$file" | cut -f 2 -d \')
	shells="${POLICY_RESELLER_PACKAGE_SHELLS:-nologin}"
	if [[ ",$shells," != *",$value,"* ]] && { [ -z "$current" ] || [ "$value" != "$(grep -m1 "^SHELL='" "$current" | cut -f 2 -d \')" ]; }; then
		echo "SHELL"
		found=1
	fi

	local templates=''
	if [ "$POLICY_USER_EDIT_WEB_TEMPLATES" != 'yes' ]; then
		templates='WEB_TEMPLATE BACKEND_TEMPLATE PROXY_TEMPLATE'
	fi
	if [ "$POLICY_USER_EDIT_DNS_TEMPLATES" != 'yes' ]; then
		templates="$templates DNS_TEMPLATE"
	fi
	for key in $templates; do
		# Empty: the server has no such system (a proxy, DNS)
		value=$(grep -m1 "^$key='" "$file" | cut -f 2 -d \')
		if [ -n "$value" ] && [ "$value" != "$(tenancy_user_value "$reseller" "$key")" ]; then
			echo "$key"
			found=1
		fi
	done

	if [ "$found" = 1 ]; then
		return "$E_LIMIT"
	fi
	return 0
}

# A package file that fits inside the reseller's plan (spec D8, E39 W4
# spec §8). Prints the first key the plan does not allow.
tenancy_check_package_limits() {
	local excess
	excess=$(tenancy_package_excess "$@")
	if [ -n "$excess" ]; then
		echo "${excess%%$'\n'*}"
		return "$E_LIMIT"
	fi
	return 0
}

# Every sidecar key of the reseller's PACKAGE above the reseller's own
# package, one per line (the 2.1.3 upgrade report)
tenancy_package_tenancy_excess() {
	local reseller="$1" package="$2" own key
	own=$(tenancy_user_value "$reseller" PACKAGE)
	for key in $TENANCY_PACKAGE_TENANCY_LIMIT_KEYS; do
		if tenancy_pkg_tenancy_above "$key" "$(tenancy_pkg_get "$package" "$key")" "$(tenancy_pkg_get "$own" "$key")"; then
			echo "$key"
		fi
	done
}

#----------------------------------------------------------#
#                         Counters                         #
#----------------------------------------------------------#

# Recompute R_* of a reseller: its own U_* plus those of its customers
tenancy_update_counters() {
	local reseller="$1" file customer key total value members=()
	file=$(tenancy_user_file "$reseller")
	members=("$reseller")
	while read -r customer; do
		if [ -n "$customer" ]; then
			members+=("$customer")
		fi
	done < <(tenancy_customers "$reseller")

	tenancy_conf_set "$file" R_USERS "$((${#members[@]} - 1))"
	for key in DISK BANDWIDTH WEB_DOMAINS DNS_DOMAINS MAIL_DOMAINS MAIL_ACCOUNTS DATABASES CRON_JOBS; do
		total=0
		for customer in "${members[@]}"; do
			value=$(tenancy_user_value "$customer" "U_$key")
			if [[ "$value" =~ ^[0-9]+$ ]]; then
				total=$((total + value))
			fi
		done
		tenancy_conf_set "$file" "R_$key" "$total"
	done
	chmod 640 "$file"
}

#----------------------------------------------------------#
#                      JSON objects                        #
#----------------------------------------------------------#

# Fields of v-list-users json (without RKEY) plus the resolved OWNER
TENANCY_USER_JSON_KEYS="NAME PACKAGE WEB_TEMPLATE BACKEND_TEMPLATE PROXY_TEMPLATE DNS_TEMPLATE WEB_DOMAINS WEB_ALIASES DNS_DOMAINS DNS_RECORDS MAIL_DOMAINS MAIL_ACCOUNTS DATABASES CRON_JOBS DISK_QUOTA CPU_QUOTA CPU_QUOTA_PERIOD MEMORY_LIMIT SWAP_LIMIT BANDWIDTH NS SHELL BACKUPS CONTACT CRON_REPORTS ROLE SUSPENDED SUSPENDED_USERS SUSPENDED_WEB SUSPENDED_DNS SUSPENDED_MAIL SUSPENDED_DB SUSPENDED_CRON IP_AVAIL IP_OWNED U_USERS U_DISK U_DISK_DIRS U_DISK_WEB U_DISK_MAIL U_DISK_DB U_BANDWIDTH U_WEB_DOMAINS U_WEB_SSL U_WEB_ALIASES U_DNS_DOMAINS U_DNS_RECORDS U_MAIL_DOMAINS U_MAIL_DKIM U_MAIL_ACCOUNTS U_DATABASES U_CRON_JOBS U_BACKUPS LANGUAGE TIME DATE"

# One "USER": {...} member of a JSON object
tenancy_user_json() {
	(
		local user="$1" key sep=''
		source_conf "$ARCPANEL/data/users/$user/user.conf"
		printf '    "%s": {\n' "$(tenancy_json_escape "$user")"
		for key in $TENANCY_USER_JSON_KEYS; do
			printf '%s        "%s": "%s"' "$sep" "$key" "$(tenancy_json_escape "${!key}")"
			sep=$',\n'
		done
		printf ',\n        "OWNER": "%s"\n    }' "$(tenancy_json_escape "$(tenancy_get_owner "$user")")"
	)
}

# Fields of v-list-user-packages json plus the tenancy keys
TENANCY_PACKAGE_JSON_KEYS="WEB_TEMPLATE BACKEND_TEMPLATE PROXY_TEMPLATE DNS_TEMPLATE WEB_DOMAINS WEB_ALIASES DNS_DOMAINS DNS_RECORDS MAIL_DOMAINS MAIL_ACCOUNTS RATE_LIMIT DATABASES CRON_JOBS DISK_QUOTA CPU_QUOTA CPU_QUOTA_PERIOD MEMORY_LIMIT SWAP_LIMIT BANDWIDTH NS SHELL BACKUPS BACKUPS_INCREMENTAL TIME DATE"

# Tenancy keys of tenancy_package_json, in output order
TENANCY_PACKAGE_TENANCY_KEYS="USERS RESELLER_PACKAGES RESELLER_VISIBLE PORTS NODE NODE_VERSIONS NODE_ALLOWED NODE_APPS DOCKER DOCKER_APPS DOCKER_CPUS DOCKER_MEMORY PHP_VERSIONS PHP_PROFILES PHP_DEFAULT_PROFILE RESELLER_OWNER"

# The sidecar is read once, in bash, with the semantics of tenancy_conf_get
# (the first KEY='value' line wins): `v-list-package-tenancy all json` runs on
# several panel pages, and a subshell per key made it cost ~70 ms a package.
tenancy_package_json() {
	(
		local package="$1" key line value sep=','
		local -A sidecar=()
		local re="^([A-Za-z0-9_]+)='([^']*)"
		local file
		file=$(tenancy_package_file "$package")
		source_conf "$ARCPANEL/data/packages/$package.pkg"
		if [ -f "$file" ]; then
			while IFS= read -r line || [ -n "$line" ]; do
				if [[ "$line" =~ $re ]] && [ -z "${sidecar[${BASH_REMATCH[1]}]+set}" ]; then
					sidecar[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
				fi
			done < "$file"
		fi
		tenancy_json_escape_reply "$package"
		printf '    "%s": {\n' "$REPLY"
		for key in $TENANCY_PACKAGE_JSON_KEYS; do
			tenancy_json_escape_reply "${!key}"
			printf '        "%s": "%s",\n' "$key" "$REPLY"
		done
		for key in $TENANCY_PACKAGE_TENANCY_KEYS; do
			value=${sidecar[$key]-}
			if [ -z "$value" ]; then
				tenancy_pkg_default "$key"
				value=$REPLY
			fi
			tenancy_json_escape_reply "$value"
			if [ "$key" = RESELLER_OWNER ]; then
				sep=''
			fi
			printf '        "%s": "%s"%s\n' "$key" "$REPLY" "$sep"
		done
		printf '    }'
	)
}
