#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel web template metadata and per-domain variables (E6)              #
#                                                                           #
# Metadata: $WEBTPL/meta/[<kind>/]<name>.json, kind web, proxy or backend.  #
# Variables: $ARCPANEL/data/users/<user>/web-tplvars/<domain>.conf, one       #
#   KEY='value' per line. The trigger <template>.sh of a template that      #
#   declares variables calls arc_apply_tpl_vars, which replaces the         #
#   %arc_<key>% tokens of the generated NGINX configs, or serves a 503 page #
#   from the "# arc:app-begin" / "# arc:app-end" block when a value is      #
#   missing.                                                                #
# The trigger sources this file without func/main.sh: functions used there  #
# only rely on ARCPANEL.                                                      #
# See docs/features/specs/2026-10-05-005-e6-templates-batch1-design.md.     #
#                                                                           #
#===========================================================================#

ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
ARC_TPL_ROOT="$ARCPANEL/data/templates/web"
ARC_TPL_META="$ARC_TPL_ROOT/meta"
ARC_TPL_CATEGORIES="php-cms php-framework ecommerce nodejs python go ruby static proxy general other"
# Templates ArcPanel ships in nginx/ and nginx/php-fpm/ (E6 batch 1)
ARC_TPL_ARCPANEL="static spa nodejs-proxy nodejs-proxy-ws docker-proxy python-gunicorn nextjs nuxt ghost strapi django flask-fastapi golang-proxy rails laravel-octane"
ARC_TPL_PORT_MIN=1024
ARC_TPL_PORT_MAX=65535

#----------------------------------------------------------#
#                   Templates and metadata                 #
#----------------------------------------------------------#

# Directory that holds the templates of KIND on this server, empty when the
# kind does not apply (same folders as the v-list-web-templates* commands)
arc_tpl_dir() {
	case "$1" in
		web)
			if [ -n "$WEB_BACKEND" ]; then
				echo "$ARC_TPL_ROOT/$WEB_SYSTEM/$WEB_BACKEND"
			else
				echo "$ARC_TPL_ROOT/$WEB_SYSTEM"
			fi
			;;
		proxy) [ -n "$PROXY_SYSTEM" ] && echo "$ARC_TPL_ROOT/$PROXY_SYSTEM" ;;
		backend) [ -n "$WEB_BACKEND" ] && echo "$ARC_TPL_ROOT/$WEB_BACKEND" ;;
	esac
	return 0
}

# Template names of KIND, with the filters of the existing list commands
arc_tpl_names() {
	local dir
	dir=$(arc_tpl_dir "$1")
	if [ -z "$dir" ] || [ ! -d "$dir" ]; then
		return 0
	fi
	case "$1" in
		web) ls -v "$dir" | grep '\.tpl$' | sed 's/\.tpl$//' ;;
		proxy) ls "$dir" | grep '\.tpl$' | cut -f1 -d . | grep -v '^proxy_ip$' | sort -u ;;
		backend) ls "$dir" | cut -f1 -d . | grep -v '^proxy_ip$' | sort -u ;;
	esac
}

# Kind of the template that faces the client: proxy on NGINX + Apache,
# web on NGINX + PHP-FPM, empty on stacks without NGINX
arc_tpl_active_kind() {
	if [ "$WEB_SYSTEM" = 'nginx' ]; then
		echo web
	elif [ "$PROXY_SYSTEM" = 'nginx' ]; then
		echo proxy
	fi
}

# Metadata file of KIND NAME, empty when there is none
arc_tpl_meta_file() {
	local kind="$1" name="$2"
	if [ -f "$ARC_TPL_META/$kind/$name.json" ]; then
		echo "$ARC_TPL_META/$kind/$name.json"
	elif [ -f "$ARC_TPL_META/$name.json" ]; then
		echo "$ARC_TPL_META/$name.json"
	elif [ "$kind" = 'backend' ] && [[ "$name" =~ ^PHP-[0-9]+_[0-9]+$ ]] && [ -f "$ARC_TPL_META/backend/PHP-x_y.json" ]; then
		echo "$ARC_TPL_META/backend/PHP-x_y.json"
	fi
}

# The jq program that turns a metadata file into a list entry. Exits with an
# error when the file does not follow the format (spec §4.2).
ARC_TPL_JQ='
	def str(f): (f | type) == "string";
	if (str(.title) and (.title | length) > 0 and (.title | length) <= 60
		and str(.description) and (.description | length) > 0 and (.description | length) <= 160
		and str(.category) and (.category as $c | $cats | index($c) != null)
		and ((.runtime // "none") as $r | ["php", "node", "python", "go", "ruby", "docker", "none"] | index($r) != null)
		and ((.internal // false) | type) == "boolean"
		and ((.variables // []) | type) == "array"
		and all((.variables // [])[]; str(.key) and (.key | test("^[A-Z][A-Z0-9_]*$")) and .type == "port"
			and ((.required // false) | type) == "boolean" and str(.label) and (.label | length) <= 40)
		and (((.docs // "") | type) == "string" and ((.docs // "") == "" or (.docs | startswith("https://")))))
	then {
		NAME: $name,
		TITLE: (.title | gsub("\\{version\\}"; $version)),
		DESCRIPTION: (.description | gsub("\\{version\\}"; $version)),
		CATEGORY: .category,
		RUNTIME: (.runtime // "none"),
		INTERNAL: (if .internal then "yes" else "no" end),
		VARIABLES: [(.variables // [])[] | {KEY: .key, TYPE: .type, REQUIRED: (if .required then "yes" else "no" end), LABEL: .label}],
		DOCS: (.docs // ""),
		HAS_META: "yes"
	}
	else error("invalid metadata") end'

# One list entry (compact JSON) for KIND NAME; a template without valid
# metadata gets its file name as title and category other
arc_tpl_meta_json() {
	local kind="$1" name="$2" file version='' entry
	file=$(arc_tpl_meta_file "$kind" "$name")
	if [[ "$name" =~ ^PHP-([0-9]+)_([0-9]+)$ ]]; then
		version="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
	fi
	if [ -n "$file" ]; then
		entry=$(jq -c --arg name "$name" --arg version "$version" \
			--argjson cats "$(arc_tpl_categories_json)" "$ARC_TPL_JQ" "$file" 2> /dev/null)
		if [ -n "$entry" ]; then
			echo "$entry"
			return 0
		fi
		arc_tpl_log_invalid "$file"
	fi
	jq -cn --arg name "$name" '{NAME: $name, TITLE: $name, DESCRIPTION: "", CATEGORY: "other", RUNTIME: "none",
		INTERNAL: (if $name == "suspended" then "yes" else "no" end), VARIABLES: [], DOCS: "", HAS_META: "no"}'
}

arc_tpl_categories_json() {
	printf '%s\n' $ARC_TPL_CATEGORIES | jq -R . | jq -cs .
}

# Log a malformed metadata file (once per command)
arc_tpl_log_invalid() {
	if [[ " $ARC_TPL_LOGGED " != *" $1 "* ]]; then
		ARC_TPL_LOGGED="$ARC_TPL_LOGGED $1"
		echo "$(date '+%F %T') arc-template: invalid metadata $1" >> "$ARCPANEL/log/system.log" 2> /dev/null
	fi
	return 0
}

# Variables declared by KIND NAME: KEY|TYPE|REQUIRED lines
arc_tpl_variables() {
	arc_tpl_meta_json "$1" "$2" | jq -r '.VARIABLES[] | "\(.KEY)|\(.TYPE)|\(.REQUIRED)"'
}

#----------------------------------------------------------#
#                         Domains                          #
#----------------------------------------------------------#

# Value of KEY in the web.conf line of USER's DOMAIN
arc_tpl_domain_value() {
	local line
	line=$(grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" 2> /dev/null)
	if [[ "$line" =~ (^| )$3=\'([^\']*)\' ]]; then
		echo "${BASH_REMATCH[2]}"
	fi
}

arc_tpl_domain_exists() {
	grep -q "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" 2> /dev/null
}

# Name of the client-facing template of USER's DOMAIN
arc_tpl_active() {
	case "$(arc_tpl_active_kind)" in
		web) arc_tpl_domain_value "$1" "$2" TPL ;;
		proxy) arc_tpl_domain_value "$1" "$2" PROXY ;;
	esac
}

#----------------------------------------------------------#
#                        Variables                         #
#----------------------------------------------------------#

# Next to the rendered configs: upstream v-backup-user copies nginx.conf* and
# v-restore-user copies it back before re-rendering, v-delete-web-domain
# removes the folder. NGINX only includes nginx.conf_*, never this file.
arc_tplvars_file() {
	echo "${3:-${HOMEDIR:-/home}}/$1/conf/web/$2/nginx.conf.tplvars"
}

# KEY|VALUE lines of a variables file (only well-formed lines)
arc_tplvars_parse() {
	if [ -f "$1" ]; then
		sed -n "s/^\([A-Z][A-Z0-9_]*\)='\([A-Za-z0-9._:-]*\)'$/\1|\2/p" "$1"
	fi
}

arc_tplvars_read() {
	arc_tplvars_parse "$(arc_tplvars_file "$1" "$2" "$3")"
}

# Value of KEY for USER's DOMAIN
arc_tplvars_get() {
	arc_tplvars_read "$1" "$2" | awk -F'|' -v k="$3" '$1 == k { print $2; exit }'
}

# Write KEY|VALUE lines (stdin) as USER's FILE (root:USER 640, like the
# rendered configs); an empty set removes the file
arc_tplvars_write() {
	local user="$1" file="$2" content
	content=$(awk -F'|' -v q="'" 'NF == 2 && $1 != "" { print $1 "=" q $2 q }')
	if [ -z "$content" ]; then
		rm -f "$file"
		return 0
	fi
	printf '%s\n' "$content" > "$file.tmp"
	chown "root:$user" "$file.tmp"
	chmod 640 "$file.tmp"
	mv -f "$file.tmp" "$file"
}

arc_tpl_valid_port() {
	[[ "$1" =~ ^[0-9]{1,5}$ ]] && [ "$((10#$1))" -ge "$ARC_TPL_PORT_MIN" ] && [ "$((10#$1))" -le "$ARC_TPL_PORT_MAX" ]
}

# True when PORT is in USER's port registry file, or the registry is off.
# Read without func/ports.sh so the template trigger can use it: a variables
# file restored from an edited backup cannot point a domain at a port of
# another user.
arc_tpl_port_owned() {
	local user="$1" port="$2" registry
	registry=$(sed -n "s/^PORT_REGISTRY='\([^']*\)'.*/\1/p" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null | tail -n1)
	if [ "$registry" = 'no' ]; then
		return 0
	fi
	awk -v q="'" -v p="$((10#$port))" '
		match($0, "^PORT=" q "[0-9-]+" q) {
			v = substr($0, 7, RLENGTH - 7)
			s = v; e = v
			if (index(v, "-")) { s = substr(v, 1, index(v, "-") - 1); e = substr(v, index(v, "-") + 1) }
			if (p >= s + 0 && p <= e + 0) { found = 1; exit }
		}
		END { exit found ? 0 : 1 }' "$ARCPANEL/data/ports/$user.conf" 2> /dev/null
}

#----------------------------------------------------------#
#                        Rendering                         #
#----------------------------------------------------------#

ARC_TPL_503_BODY="<!doctype html><title>App not configured</title><h1>This site&#39;s app is not configured yet.</h1>"

# Replace the %arc_*% tokens of the NGINX configs of USER's DOMAIN; serve a
# 503 page from the app block when a value is missing. Safe to run twice.
arc_apply_tpl_vars() {
	local user="$1" domain="$2" home="${3:-/home}" conf key value pairs
	pairs=$(arc_tplvars_read "$user" "$domain" "$home")
	for conf in "$home/$user/conf/web/$domain/nginx.conf" "$home/$user/conf/web/$domain/nginx.ssl.conf"; do
		if [ ! -f "$conf" ] || ! grep -q '%arc_' "$conf"; then
			continue
		fi
		while IFS='|' read -r key value; do
			[ -n "$key" ] || continue
			if [[ "$key" == *PORT ]] && { ! arc_tpl_valid_port "$value" || ! arc_tpl_port_owned "$user" "$value"; }; then
				continue
			fi
			sed -i "s|%arc_${key,,}%|$value|g" "$conf"
		done <<< "$pairs"
		if grep -q '%arc_' "$conf"; then
			awk -v body="$ARC_TPL_503_BODY" '
				/# arc:app-begin/ {
					print
					match($0, /^[ \t]*/); ind = substr($0, 1, RLENGTH)
					print ind "location / {"
					print ind "\tdefault_type text/html;"
					print ind "\treturn 503 '\''" body "'\'';"
					print ind "}"
					skip = 1
					next
				}
				/# arc:app-end/ { skip = 0 }
				!skip { print }' "$conf" > "$conf.arc-tmp"
			cat "$conf.arc-tmp" > "$conf"
			rm -f "$conf.arc-tmp"
		fi
	done
	return 0
}

#----------------------------------------------------------#
#                    Port registry links                   #
#----------------------------------------------------------#

# Findings for ports assigned to web:<domain> (TYPE|USER|PORT|PROTO|RULE|
# DETAIL, as ports_findings): the domain is gone, its template takes no
# port, or its variables do not hold the port
arc_tpl_port_findings() {
	local records="$1" user port proto app domain active kind detail
	kind=$(arc_tpl_active_kind)
	while IFS='|' read -r user port proto app; do
		[ -n "$user" ] || continue
		domain=${app#web:}
		detail=''
		if ! arc_tpl_domain_exists "$user" "$domain"; then
			detail="web domain $domain does not exist"
		else
			active=$(arc_tpl_active "$user" "$domain")
			if [ -z "$kind" ] || [ -z "$(arc_tpl_variables "$kind" "$active" | grep '|port|')" ]; then
				detail="template $active of $domain takes no port"
			elif ! arc_tplvars_read "$user" "$domain" | grep -q "|$port\$"; then
				detail="$domain does not use this port"
			fi
		fi
		if [ -n "$detail" ]; then
			echo "orphan-web|$user|$port|$proto||$detail"
		fi
	done < <(printf '%s\n' "$records" | awk -F'|' 'NF && $1 != "@reserved" && $2 == $3 && index($5, "web:") == 1 { print $1 "|" $2 "|" $4 "|" $5 }')
}
