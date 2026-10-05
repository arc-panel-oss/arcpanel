#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel tenancy functions: resellers and the customers they own (E11)    #
#                                                                           #
# Upstream commands rewrite user.conf and *.pkg with a fixed list of keys,  #
# so tenancy data lives in sidecar files that they never touch:             #
#   $HESTIA/data/users/<user>/tenancy.conf     OWNER, R_* (resellers)       #
#   $HESTIA/data/packages/<package>.tenancy    USERS, RESELLER_PACKAGES,    #
#                                              RESELLER_VISIBLE,            #
#                                              RESELLER_OWNER               #
# See docs/features/specs/2026-10-05-003-e11-reseller-design.md.            #
#                                                                           #
#===========================================================================#

# Package limits that are totals over a reseller's tree (spec D7), and the
# user.conf counter that holds the reseller's own usage of each.
TENANCY_QUOTA_KEYS="WEB_DOMAINS DNS_DOMAINS MAIL_DOMAINS MAIL_ACCOUNTS DATABASES CRON_JOBS DISK_QUOTA BANDWIDTH"

# Package limits a reseller-created package may not raise above the
# reseller's own (spec D8)
TENANCY_PACKAGE_LIMIT_KEYS="WEB_DOMAINS WEB_ALIASES DNS_DOMAINS DNS_RECORDS MAIL_DOMAINS MAIL_ACCOUNTS RATE_LIMIT DATABASES CRON_JOBS DISK_QUOTA CPU_QUOTA MEMORY_LIMIT SWAP_LIMIT BANDWIDTH BACKUPS"

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
	echo "$HESTIA/data/users/$1/tenancy.conf"
}

tenancy_package_file() {
	echo "$HESTIA/data/packages/$1.tenancy"
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
	local s="$1"
	s="${s//\\/\\\\}"
	s="${s//\"/\\\"}"
	s="${s//$'\t'/\\t}"
	s="${s//$'\r'/}"
	s="${s//$'\n'/\\n}"
	printf '%s' "$s"
}

#----------------------------------------------------------#
#                     Users and owners                     #
#----------------------------------------------------------#

# Every panel user, one per line
tenancy_all_users() {
	local dir
	for dir in "$HESTIA"/data/users/*/; do
		dir="${dir%/}"
		if [ -f "$dir/user.conf" ]; then
			basename "$dir"
		fi
	done
}

tenancy_user_value() {
	grep -m1 "^$2='" "$HESTIA/data/users/$1/user.conf" 2> /dev/null | cut -f 2 -d \'
}

tenancy_role() {
	tenancy_user_value "$1" ROLE
}

tenancy_is_reseller() {
	[ -n "$1" ] && [ -f "$HESTIA/data/users/$1/user.conf" ] && [ "$(tenancy_role "$1")" = 'reseller' ]
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

# Tenancy value of a package with its default
tenancy_pkg_get() {
	local value
	value=$(tenancy_conf_get "$(tenancy_package_file "$1")" "$2")
	if [ -z "$value" ]; then
		case "$2" in
			USERS) value='0' ;;
			RESELLER_VISIBLE) value='no' ;;
		esac
	fi
	echo "$value"
}

tenancy_pkg_set() {
	local file
	file=$(tenancy_package_file "$1")
	tenancy_conf_set "$file" "$2" "$3" || return $?
	chmod 644 "$file"
}

# Value of a limit in a package file
tenancy_pkg_limit() {
	grep -m1 "^$2='" "$HESTIA/data/packages/$1.pkg" 2> /dev/null | cut -f 2 -d \'
}

tenancy_all_packages() {
	local file
	for file in "$HESTIA"/data/packages/*.pkg; do
		if [ -f "$file" ]; then
			basename "$file" .pkg
		fi
	done
}

# Validate a tenancy value of a package
tenancy_pkg_value_valid() {
	local key="$1" value="$2" item
	case "$key" in
		USERS)
			[ "$value" = 'unlimited' ] || [[ "$value" =~ ^[0-9]+$ ]]
			;;
		RESELLER_VISIBLE)
			[ "$value" = 'yes' ] || [ "$value" = 'no' ]
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
	return 0
}

# A package file whose limits all fit inside the reseller's own limits
# (spec D8). Prints the first key above the reseller's limit.
tenancy_check_package_limits() {
	local reseller="$1" file="$2" key limit value
	for key in $TENANCY_PACKAGE_LIMIT_KEYS; do
		limit=$(tenancy_user_value "$reseller" "$key")
		if [ -z "$limit" ] || [ "$limit" = 'unlimited' ]; then
			continue
		fi
		value=$(grep -m1 "^$key='" "$file" | cut -f 2 -d \')
		if [ -z "$value" ]; then
			continue
		fi
		if [ "$value" = 'unlimited' ]; then
			echo "$key"
			return "$E_LIMIT"
		fi
		if [[ "$value" =~ ^[0-9]+$ ]] && [[ "$limit" =~ ^[0-9]+$ ]] && [ "$value" -gt "$limit" ]; then
			echo "$key"
			return "$E_LIMIT"
		fi
	done
	return 0
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
		source_conf "$HESTIA/data/users/$user/user.conf"
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

tenancy_package_json() {
	(
		local package="$1" key
		source_conf "$HESTIA/data/packages/$package.pkg"
		printf '    "%s": {\n' "$(tenancy_json_escape "$package")"
		for key in $TENANCY_PACKAGE_JSON_KEYS; do
			printf '        "%s": "%s",\n' "$key" "$(tenancy_json_escape "${!key}")"
		done
		printf '        "USERS": "%s",\n' "$(tenancy_json_escape "$(tenancy_pkg_get "$package" USERS)")"
		printf '        "RESELLER_PACKAGES": "%s",\n' "$(tenancy_json_escape "$(tenancy_pkg_get "$package" RESELLER_PACKAGES)")"
		printf '        "RESELLER_VISIBLE": "%s",\n' "$(tenancy_json_escape "$(tenancy_pkg_get "$package" RESELLER_VISIBLE)")"
		printf '        "RESELLER_OWNER": "%s"\n    }' "$(tenancy_json_escape "$(tenancy_pkg_get "$package" RESELLER_OWNER)")"
	)
}
