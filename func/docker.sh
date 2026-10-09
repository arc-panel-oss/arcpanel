#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Docker app functions: curated Compose apps of the catalog, run   #
# by the root Docker Engine for hosting users (E4)                          #
#                                                                           #
#   $ARCPANEL/install/common/apps/docker/<slug>/<version>/  the catalog       #
#   $ARCPANEL/data/users/<user>/dockerapps.conf  the app records              #
#   $ARCPANEL/data/users/<user>/docker/<name>/   compose.yaml (catalog copy), #
#                                              compose.arcpanel.yaml,       #
#                                              .env, task.*, root only      #
#   ~/apps/<name>/                             data/, app.conf and .env     #
#                                              copies, .snapshots/          #
# Everything in a home directory is read and written as the user. Every    #
# service runs as the user's uid:gid without capabilities, in the user's   #
# slice (spec D3, D12).                                                     #
# See docs/features/specs/2026-10-05-009-e4-docker-apps-design.md.          #
#                                                                           #
#===========================================================================#

# shellcheck source=/usr/local/arcpanel/func/nodeapp.sh
source "$ARCPANEL/func/nodeapp.sh"

DOCKERAPP_KEYS="NAME SLUG VERSION DOMAIN WEB_PORT EXTRA_PORTS STATE STATUS REASON SUSPENDED TEMPLATE PREV_TPL PREV_VERSION CPUS MEMORY TIME DATE"
DOCKERAPP_TEMPLATE="docker-proxy"
DOCKERAPP_TASK_KEYS="ID ACTION ARGS STATUS PID EXIT START END"
DOCKER_CATEGORIES="monitoring automation dev cms storage security database analytics other"

# Docker's apt repository and the fingerprint of its signing key
DOCKER_APT_URL="${DOCKER_APT_URL:-https://download.docker.com/linux}"
DOCKER_APT_FINGERPRINT="9DC858229FC7DD38854AE2D88D81803C0EBFCD88"
DOCKER_KEYRING="/etc/apt/keyrings/docker.asc"
DOCKER_DAEMON_JSON="/etc/docker/daemon.json"

# Clean environment of every docker call: the shell environment would win
# over the .env file of a project in Compose interpolation
DOCKER_ENV=(env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8)

docker_catalog_dir() {
	echo "${ARC_DOCKER_CATALOG:-$ARCPANEL/install/common/apps/docker}"
}

docker_state_dir() {
	echo "$ARCPANEL/data/docker"
}

# Exit with E_DISABLED unless the engine was installed with v-add-sys-docker
docker_check_system() {
	if [ "$DOCKER_SYSTEM" != 'yes' ] || ! command -v docker > /dev/null 2>&1; then
		check_result "$E_DISABLED" "the Docker engine is not installed on this server (v-add-sys-docker)"
	fi
}

docker_cmd() {
	"${DOCKER_ENV[@]}" docker "$@"
}

#----------------------------------------------------------#
#                       Engine                             #
#----------------------------------------------------------#

docker_arch() {
	dpkg --print-architecture 2> /dev/null
}

# Distribution id and codename of the server when Docker publishes packages
# for it; returns 1 with the reason otherwise
docker_os_check() {
	local id codename arch
	id=$(. /etc/os-release && echo "$ID")
	codename=$(. /etc/os-release && echo "$VERSION_CODENAME")
	arch=$(docker_arch)
	case "$id" in
		debian | ubuntu) ;;
		*)
			echo "Docker apps support Debian and Ubuntu, not $id"
			return 1
			;;
	esac
	case "$arch" in
		amd64 | arm64) ;;
		*)
			echo "Docker apps support amd64 and arm64, not $arch"
			return 1
			;;
	esac
	if ! curl -fsS --max-time 20 -o /dev/null "$DOCKER_APT_URL/$id/dists/$codename/Release"; then
		echo "Docker publishes no packages for $id $codename at $DOCKER_APT_URL"
		return 1
	fi
	echo "$id $codename"
}

# Add Docker's apt repository with its key, refusing a key with another
# fingerprint
docker_repo_add() {
	local id="$1" codename="$2" tmp fpr
	tmp=$(mktemp)
	if ! curl -fsSL --max-time 30 "$DOCKER_APT_URL/$id/gpg" -o "$tmp"; then
		rm -f "$tmp"
		echo "the key of Docker's repository could not be downloaded"
		return "$E_CONNECT"
	fi
	fpr=$(gpg --show-keys --with-colons "$tmp" 2> /dev/null | awk -F: '$1 == "fpr" { print $10; exit }')
	if [ "$fpr" != "$DOCKER_APT_FINGERPRINT" ]; then
		rm -f "$tmp"
		echo "the key of Docker's repository has an unexpected fingerprint ($fpr)"
		return "$E_FORBIDEN"
	fi
	install -m 0755 -d /etc/apt/keyrings
	install -m 0644 "$tmp" "$DOCKER_KEYRING"
	rm -f "$tmp"
	echo "deb [arch=$(docker_arch) signed-by=$DOCKER_KEYRING] $DOCKER_APT_URL/$id $codename stable" \
		> /etc/apt/sources.list.d/docker.list
}

# daemon.json of a new engine (kept when it exists)
docker_daemon_json() {
	jq -n --arg root "$1" '{
		"log-driver": "json-file",
		"log-opts": { "max-size": "10m", "max-file": "3" },
		"live-restore": true,
		"userland-proxy": false,
		"data-root": $root,
		"default-address-pools": [ { "base": "172.30.0.0/16", "size": 24 } ]
	}'
}

#----------------------------------------------------------#
#                        Records                           #
#----------------------------------------------------------#

dockerapp_conf() {
	echo "$ARCPANEL/data/users/$1/dockerapps.conf"
}

# Serialise the writers of USER's records. Nested commands run inside the
# caller's lock.
dockerapp_lock() {
	if [ "$ARC_DOCKERAPPS_LOCKED" = "$1" ]; then
		return 0
	fi
	exec {DOCKERAPP_LOCK_FD}> "$ARCPANEL/data/users/$1/dockerapps.lock"
	if ! flock -w 120 "$DOCKERAPP_LOCK_FD"; then
		check_result "$E_LA" "the Docker apps of $1 are locked by another command"
	fi
	export ARC_DOCKERAPPS_LOCKED="$1"
}

dockerapp_line() {
	local file
	file=$(dockerapp_conf "$1")
	if [ -f "$file" ]; then
		grep -m1 "^NAME='$2' " "$file"
	fi
}

dockerapp_exists() {
	[ -n "$(dockerapp_line "$1" "$2")" ]
}

dockerapp_get() {
	nodeapp_field "$(dockerapp_line "$1" "$2")" "$3"
}

# Names of USER's apps, in record order
dockerapp_names() {
	local file
	file=$(dockerapp_conf "$1")
	if [ -f "$file" ]; then
		grep -oE "^NAME='[^']*'" "$file" | cut -d\' -f2
	fi
}

dockerapp_count() {
	dockerapp_names "$1" | grep -c .
}

# Name of the app of USER bound to DOMAIN, empty when there is none
dockerapp_of_domain() {
	local file
	file=$(dockerapp_conf "$1")
	if [ -f "$file" ] && [ -n "$2" ]; then
		grep -m1 " DOMAIN='$2' " "$file" | grep -oE "^NAME='[^']*'" | cut -d\' -f2
	fi
}

# Users with records
dockerapp_users() {
	local file
	for file in "$ARCPANEL"/data/users/*/dockerapps.conf; do
		if [ -s "$file" ]; then
			basename "$(dirname "$file")"
		fi
	done
}

# A record line from KEY=VALUE pairs on top of LINE (may be empty). Quotes
# and line breaks are dropped from values.
dockerapp_compose_line() {
	local line="$1" pair key out=''
	local -A values=()
	shift
	for key in $DOCKERAPP_KEYS; do
		values[$key]=$(nodeapp_field "$line" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $DOCKERAPP_KEYS; do
		out="$out$key='${values[$key]}' "
	done
	echo "${out% }"
}

# Create or update the record of USER's NAME with KEY=VALUE pairs and
# refresh its copy
dockerapp_set() {
	local user="$1" name="$2" file line tmp
	shift 2
	file=$(dockerapp_conf "$user")
	line=$(dockerapp_compose_line "$(dockerapp_line "$user" "$name")" "NAME=$name" "$@")
	tmp="$file.$$"
	{
		if [ -f "$file" ]; then
			grep -v "^NAME='$name' " "$file"
		fi
		echo "$line"
	} > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
	dockerapp_copy_write "$user" "$name"
}

dockerapp_remove() {
	local user="$1" name="$2" file tmp
	file=$(dockerapp_conf "$user")
	if [ ! -f "$file" ]; then
		return 0
	fi
	tmp="$file.$$"
	grep -v "^NAME='$name' " "$file" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
	if [ ! -s "$file" ]; then
		rm -f "$file"
	fi
}

#----------------------------------------------------------#
#                 Paths, names and sizes                   #
#----------------------------------------------------------#

# Rendered project of USER's NAME (root only)
dockerapp_rdir() {
	echo "$ARCPANEL/data/users/$1/docker/$2"
}

# Folder of USER's NAME in the home directory
dockerapp_home() {
	echo "$HOMEDIR/$1/apps/$2"
}

dockerapp_data_dir() {
	echo "$HOMEDIR/$1/apps/$2/data"
}

dockerapp_name_valid() {
	[[ "$1" =~ ^[a-z][a-z0-9-]{1,31}$ ]] && [[ "$1" != *- ]]
}

# uid and gid of USER; DOCKER_RENDER_UID and DOCKER_RENDER_GID stand in for
# the user when the catalog is checked without one (v-check-docker-catalog)
docker_uid() {
	if [ -n "$DOCKER_RENDER_UID" ]; then
		echo "$DOCKER_RENDER_UID"
	else
		id -u "$1"
	fi
}

docker_gid() {
	if [ -n "$DOCKER_RENDER_GID" ]; then
		echo "$DOCKER_RENDER_GID"
	else
		id -g "$1"
	fi
}

dockerapp_project() {
	echo "ap-$(docker_uid "$1")-$2"
}

# Size like 512M, 2G or 1024 (MB) in MB; returns 1 when invalid
docker_mb() {
	if [[ "$1" =~ ^([0-9]{1,7})([MmGg]?)$ ]]; then
		case "${BASH_REMATCH[2]}" in
			G | g) echo $((BASH_REMATCH[1] * 1024)) ;;
			*) echo "${BASH_REMATCH[1]}" ;;
		esac
		return 0
	fi
	return 1
}

# CPUs like 0.5 or 2; returns 1 when invalid
docker_cpus_valid() {
	[[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,2})?$ ]] && [ "$(awk -v c="$1" 'BEGIN { print (c > 0) ? 1 : 0 }')" = 1 ]
}

# Whether CPUs A is greater than B
docker_cpus_gt() {
	[ "$(awk -v a="$1" -v b="$2" 'BEGIN { print (a > b) ? 1 : 0 }')" = 1 ]
}

# Free MB on the file system of PATH
docker_free_mb() {
	df -Pm "$1" 2> /dev/null | awk 'NR == 2 { print $4 }'
}

#----------------------------------------------------------#
#                        Catalog                           #
#----------------------------------------------------------#

docker_app_dir() {
	echo "$(docker_catalog_dir)/$1/$2"
}

docker_manifest() {
	echo "$(docker_catalog_dir)/$1/$2/manifest.json"
}

docker_slug_valid() {
	[[ "$1" =~ ^[a-z][a-z0-9-]{1,31}$ ]]
}

docker_version_valid() {
	[[ "$1" =~ ^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$ ]]
}

docker_slugs() {
	local dir
	for dir in "$(docker_catalog_dir)"/*/; do
		dir=${dir%/}
		if [ -d "$dir" ] && docker_slug_valid "${dir##*/}"; then
			echo "${dir##*/}"
		fi
	done
}

# Versions of SLUG in the catalog, oldest first
docker_versions() {
	local dir
	for dir in "$(docker_catalog_dir)/$1"/*/; do
		dir=${dir%/}
		if [ -f "$dir/manifest.json" ] && docker_version_valid "${dir##*/}"; then
			echo "${dir##*/}"
		fi
	done | sort -V
}

docker_latest_version() {
	docker_versions "$1" | tail -n1
}

docker_version_exists() {
	docker_slug_valid "$1" && docker_version_valid "$2" && [ -f "$(docker_manifest "$1" "$2")" ]
}

# Whether version A is newer than B
docker_version_newer() {
	[ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$1" ]
}

# Errors of the manifest of SLUG VERSION in DIR, one per line (none when it
# is valid)
docker_manifest_errors() {
	local file="$1" slug="$2" version="$3"
	if ! jq -e 'type == "object"' "$file" > /dev/null 2>&1; then
		echo "manifest.json is not a JSON object"
		return 0
	fi
	jq -r --arg slug "$slug" --arg version "$version" --arg cats "$DOCKER_CATEGORIES" '
		def str($k; $re): (.[$k] | type == "string") and (.[$k] | test($re));
		def size: type == "string" and test("^[0-9]{1,7}[MG]$");
		def cpus: type == "string" and test("^[0-9]{1,3}(\\.[0-9]{1,2})?$");
		def svc: type == "string" and test("^[a-z0-9][a-z0-9_.-]{0,62}$");
		. as $m
		| [
			(if .schema != 1 then "schema must be 1" else empty end),
			(if (str("slug"; "^[a-z][a-z0-9-]{1,31}$") | not) or .slug != $slug then "slug must be \($slug)" else empty end),
			(if .version != $version then "version must be \($version)" else empty end),
			(if str("name"; "^.{1,64}$") | not then "name: 1 to 64 characters" else empty end),
			(if str("summary"; "^.{1,200}$") | not then "summary: 1 to 200 characters" else empty end),
			(if (.description // "") | type != "string" then "description must be a string" else empty end),
			(if [($cats | split(" "))[] | select(. == $m.category)] | length == 0 then "category must be one of: \($cats)" else empty end),
			(if (.homepage // "https://x") | (type != "string" or (test("^https://[^ ]+$") | not)) then "homepage must be an https URL" else empty end),
			(if (.changelog // "https://x") | (type != "string" or (test("^https://[^ ]+$") | not)) then "changelog must be an https URL" else empty end),
			(if (.license // "") | type != "string" then "license must be a string" else empty end),
			(if (.admin_only // false) | type != "boolean" then "admin_only must be a boolean" else empty end),
			(if (.arch | type != "array") or (.arch | length == 0) or ([.arch[] | select(. != "amd64" and . != "arm64")] | length > 0)
				then "arch must list amd64 and/or arm64" else empty end),
			(if (.images | type != "array") or (.images | length == 0) then "images must list at least one image"
				else (.images[] | select(type != "string" or (test("^[a-z0-9][a-z0-9._/:-]*@sha256:[a-f0-9]{64}$") | not))
					| "image \(.) must be pinned by digest (name:tag@sha256:...)") end),
			(if (.requirements.memory | size | not) or (.requirements.disk | size | not)
				then "requirements.memory and requirements.disk must be sizes like 256M or 1G" else empty end),
			(if (.limits.memory | size | not) or (.limits.cpus | cpus | not)
				then "limits.memory must be a size and limits.cpus a number like 0.5" else empty end),
			(if (.ports // {}) | type != "object" then "ports must be an object"
				else ((.ports // {}) | to_entries[]
					| (if (.key | test("^[a-z][a-z0-9]{0,15}$") | not) then "invalid port name \(.key)" else empty end),
						(if (.value.service | svc | not) then "port \(.key): invalid service" else empty end),
						(if (.value.container | type != "number") or .value.container < 1 or .value.container > 65535 or (.value.container | floor) != .value.container
							then "port \(.key): container must be a port number" else empty end),
						(if (.value.description // "") | type != "string" then "port \(.key): description must be a string" else empty end)) end),
			(if (.volumes // []) | type != "array" then "volumes must be an array"
				else ((.volumes // [])[] | select((.name | type != "string") or (.name | test("^[a-z][a-z0-9_]{0,31}$") | not))
					| "invalid volume name \(.name)") end),
			(if ([(.volumes // [])[].name] | length) != ([(.volumes // [])[].name] | unique | length) then "volume names must be unique" else empty end),
			(if (.variables // []) | type != "array" then "variables must be an array"
				else ((.variables // [])[]
					| (if (.name | type != "string") or (.name | test("^[A-Z][A-Z0-9_]{0,63}$") | not) or (.name | startswith("ARC_"))
						then "invalid variable name \(.name)" else empty end),
						(if (.label | type != "string") or (.label | length == 0) then "variable \(.name): label required" else empty end),
						(.type as $t | if ["string", "int", "bool", "enum", "password", "email", "url"] | index($t) == null then "variable \(.name): invalid type \(.type)" else empty end),
						(if .type == "enum" and ((.options | type != "array") or (.options | length == 0) or ([.options[] | select(type != "string")] | length > 0))
							then "variable \(.name): enum needs options" else empty end),
						(if (.default // "") | type != "string" then "variable \(.name): default must be a string" else empty end),
						(if (.pattern // "") | type != "string" then "variable \(.name): pattern must be a string" else empty end),
						(if ((.required // false) | type != "boolean") or ((.immutable // false) | type != "boolean")
							then "variable \(.name): required and immutable are booleans" else empty end)) end),
			(if ([(.variables // [])[].name] | length) != ([(.variables // [])[].name] | unique | length) then "variable names must be unique" else empty end),
			(if .healthcheck.type == "http" then
				(if .ports.web == null then "an http health check needs a web port" else empty end),
				(if (.healthcheck.path | type != "string") or (.healthcheck.path | test("^/[^ ]*$") | not) then "healthcheck.path must start with /" else empty end),
				(if (.healthcheck.expect | type != "array") or ([.healthcheck.expect[] | select(type != "number")] | length > 0) or (.healthcheck.expect | length == 0)
					then "healthcheck.expect must list status codes" else empty end)
			elif .healthcheck.type == "container" then empty
			else "healthcheck.type must be http or container" end),
			(if (.healthcheck.timeout | type != "number") or .healthcheck.timeout < 10 or .healthcheck.timeout > 1800
				then "healthcheck.timeout must be 10 to 1800 seconds" else empty end),
			(if (.dumps // []) | type != "array" then "dumps must be an array"
				else ((.dumps // [])[]
					| (if (.service | svc | not) then "dump: invalid service" else empty end),
						(if (.command | type != "string") or (.command | length == 0) then "dump: command required" else empty end),
						(if (.file | type != "string") or (.file | test("^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$") | not) then "dump: invalid file name" else empty end)) end),
			(if (.post_install // "") | type != "string" then "post_install must be a string" else empty end)
		] | .[]' "$file" 2>&1
}

# Catalog app SLUG of VERSION as JSON for the panel: manifest, icon as a data
# URI and the versions of the app
docker_catalog_app_json() {
	local slug="$1" version="$2" icon='' versions
	if [ -f "$(docker_catalog_dir)/$slug/icon.svg" ]; then
		icon="data:image/svg+xml;base64,$(base64 -w0 < "$(docker_catalog_dir)/$slug/icon.svg")"
	fi
	versions=$(docker_versions "$slug" | jq -R . | jq -sc .)
	jq -c --arg icon "$icon" --argjson versions "$versions" \
		'. + { icon: $icon, versions: $versions, latest: $versions[-1] }' "$(docker_manifest "$slug" "$version")"
}

#----------------------------------------------------------#
#                       Variables                          #
#----------------------------------------------------------#

docker_random() {
	local out=''
	while [ "${#out}" -lt "$1" ]; do
		out="$out$(head -c 256 /dev/urandom | tr -dc 'A-Za-z0-9')"
	done
	echo "${out:0:$1}"
}

docker_timezone() {
	local tz
	tz=$(timedatectl show -p Timezone --value 2> /dev/null)
	if [ -z "$tz" ] && [ -f /etc/timezone ]; then
		tz=$(head -n1 /etc/timezone)
	fi
	echo "${tz:-UTC}"
}

# URL of USER's DOMAIN (https when the domain has SSL)
dockerapp_url() {
	local user="$1" domain="$2"
	if [ -z "$domain" ]; then
		return 0
	fi
	if [ "$(arc_tpl_domain_value "$user" "$domain" SSL)" = 'yes' ]; then
		echo "https://$domain"
	else
		echo "http://$domain"
	fi
}

# Replace the {{…}} placeholders of TEMPLATE for USER's app on DOMAIN
docker_interpolate() {
	local text="$1" user="$2" domain="$3" n email
	if [[ "$text" == *"{{server.timezone}}"* ]]; then
		text=${text//\{\{server.timezone\}\}/$(docker_timezone)}
	fi
	text=${text//\{\{domain\}\}/$domain}
	text=${text//\{\{url\}\}/$(dockerapp_url "$user" "$domain")}
	text=${text//\{\{user\}\}/$user}
	if [[ "$text" == *"{{user.email}}"* ]]; then
		email=$(grep -m1 "^CONTACT=" "$ARCPANEL/data/users/$user/user.conf" 2> /dev/null | cut -d\' -f2)
		text=${text//\{\{user.email\}\}/$email}
	fi
	while [[ "$text" =~ \{\{random:([0-9]{1,3})\}\} ]]; do
		n=${BASH_REMATCH[1]}
		text=${text/\{\{random:$n\}\}/$(docker_random "$n")}
	done
	printf '%s' "$text"
}

# Validate VALUE of the variable JSON VAR; prints the reason and returns 1
# when it is invalid
docker_var_check() {
	local var="$1" value="$2" type pattern name
	name=$(jq -r .name <<< "$var")
	type=$(jq -r .type <<< "$var")
	if [ "${#value}" -gt 1024 ] || [[ "$value" == *[\'\"\$\`\\]* ]] || [[ "$value" == *$'\n'* ]] || [[ "$value" == *$'\r'* ]]; then
		echo "$name: at most 1024 characters, without quotes, \$, \` or \\"
		return 1
	fi
	if [ -z "$value" ]; then
		return 0
	fi
	case "$type" in
		int) [[ "$value" =~ ^-?[0-9]{1,18}$ ]] || {
			echo "$name: a whole number"
			return 1
		} ;;
		bool) [[ "$value" =~ ^(true|false)$ ]] || {
			echo "$name: true or false"
			return 1
		} ;;
		enum) jq -e --arg v "$value" '.options | index($v) != null' <<< "$var" > /dev/null || {
			echo "$name: one of $(jq -r '.options | join(", ")' <<< "$var")"
			return 1
		} ;;
		password) [ "${#value}" -ge 8 ] || {
			echo "$name: at least 8 characters"
			return 1
		} ;;
		email) [[ "$value" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || {
			echo "$name: an email address"
			return 1
		} ;;
		url) [[ "$value" =~ ^https?://[^[:space:]]+$ ]] || {
			echo "$name: an http or https URL"
			return 1
		} ;;
	esac
	pattern=$(jq -r '.pattern // ""' <<< "$var")
	if [ -n "$pattern" ] && ! jq -e --arg v "$value" --arg p "$pattern" '$v | test($p)' -n > /dev/null 2>&1; then
		echo "$name: does not match $pattern"
		return 1
	fi
	return 0
}

# Read KEY='value' (or KEY=value) lines of an .env file into the associative
# array named by $2
docker_env_read() {
	local file="$1" line key value
	local -n into="$2"
	if [ ! -f "$file" ]; then
		return 0
	fi
	while IFS= read -r line || [ -n "$line" ]; do
		line=${line%$'\r'}
		[[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
		key=${BASH_REMATCH[1]}
		value=${BASH_REMATCH[2]}
		if [[ "$value" =~ ^\'(.*)\'$ ]] || [[ "$value" =~ ^\"(.*)\"$ ]]; then
			value=${BASH_REMATCH[1]}
		fi
		into[$key]=$value
	done < "$file"
}

# Render the variables of manifest FILE for USER's app NAME on DOMAIN into
# OUT (an .env file). INPUT holds NAME=value lines given by the caller,
# CURRENT the current .env (both may be missing). MODE:
#   install  given values, else defaults ({{random:N}} generates secrets)
#   change   given values replace current ones (an empty secret keeps its
#            value); unknown names and new values of immutable variables
#            are refused; variables not given keep their values
#   keep     current values; new variables get their defaults (updates,
#            rebuilds)
# SPEC lists the app's ports as "portname:hostport:public" words; each host
# port is written as ARC_PORT_<NAME>. Prints the reason and returns
# E_INVALID on a bad value.
dockerapp_env_render() {
	local manifest="$1" user="$2" name="$3" domain="$4" input="$5" current="$6" out="$7" mode="$8" spec="$9"
	local -A given=() now=()
	local var vname type value required immutable key count i volume reason text word pname resolved rc
	docker_env_read "$input" given
	docker_env_read "$current" now
	if [ "$mode" != 'keep' ]; then
		for key in "${!given[@]}"; do
			if ! jq -e --arg n "$key" '(.variables // []) | map(.name) | index($n) != null' "$manifest" > /dev/null; then
				echo "unknown variable $key"
				return "$E_INVALID"
			fi
		done
	fi
	text="# Generated by ArcPanel for the Docker app $name of $user; changes are overwritten."$'\n'
	count=$(jq '(.variables // []) | length' "$manifest")
	for ((i = 0; i < count; i++)); do
		var=$(jq -c ".variables[$i]" "$manifest")
		vname=$(jq -r .name <<< "$var")
		type=$(jq -r .type <<< "$var")
		required=$(jq -r '.required // false' <<< "$var")
		immutable=$(jq -r '.immutable // false' <<< "$var")
		if [ "$mode" = 'install' ]; then
			value=${given[$vname]}
		elif [ "$mode" = 'change' ] && [ -n "${given[$vname]+x}" ]; then
			value=${given[$vname]}
			if [ -z "$value" ] && [ "$type" = 'password' ]; then
				value=${now[$vname]}
			fi
			if [ "$immutable" = 'true' ] && [ -n "${now[$vname]+x}" ] && [ "$value" != "${now[$vname]}" ]; then
				echo "$vname cannot be changed after the installation"
				return "$E_INVALID"
			fi
		elif [ -n "${now[$vname]+x}" ]; then
			value=${now[$vname]}
		else
			value=$(docker_interpolate "$(jq -r '.default // ""' <<< "$var")" "$user" "$domain")
		fi
		if [ "$mode" = 'install' ] && [ -z "$value" ]; then
			value=$(docker_interpolate "$(jq -r '.default // ""' <<< "$var")" "$user" "$domain")
		fi
		# A value that references secrets (E38) is checked as the value it
		# resolves to; the .env keeps the reference
		resolved=$value
		if [[ "$value" == *'{{secret:'* ]]; then
			if ! secret_store_ready; then
				echo "$vname: the secret store is not set up"
				return "$E_NOTEXIST"
			fi
			secret_resolve_into "$user" "$value" resolved
			rc=$?
			if [ "$rc" -ne 0 ]; then
				echo "$vname: $SECRET_REASON"
				return "$rc"
			fi
			if ! docker_secret_value_ok "$value"; then
				echo "$vname: at most 1024 characters, without quotes, \$, \` or \\"
				return "$E_INVALID"
			fi
		fi
		if ! reason=$(docker_var_check "$var" "$resolved"); then
			echo "$reason"
			return "$E_INVALID"
		fi
		if [ -z "$value" ] && [ "$required" = 'true' ]; then
			echo "$vname is required"
			return "$E_INVALID"
		fi
		text+="$vname='$value'"$'\n'
	done
	text+="ARC_UID='$(docker_uid "$user")'"$'\n'"ARC_GID='$(docker_gid "$user")'"$'\n'
	text+="ARC_USER='$user'"$'\n'"ARC_APP='$name'"$'\n'
	text+="ARC_DOMAIN='$domain'"$'\n'"ARC_URL='$(dockerapp_url "$user" "$domain")'"$'\n'
	for volume in $(jq -r '(.volumes // [])[].name' "$manifest"); do
		text+="ARC_DATA_${volume^^}='$(dockerapp_data_dir "$user" "$name")/$volume'"$'\n'
	done
	for word in $spec; do
		word=${word%:*}
		pname=${word%%:*}
		text+="ARC_PORT_${pname^^}='${word#*:}'"$'\n'
	done
	(umask 077 && printf '%s' "$text" > "$out.tmp") || return "$E_INVALID"
	mv -f "$out.tmp" "$out"
}

#----------------------------------------------------------#
#                 Rendering and checks                     #
#----------------------------------------------------------#

# docker compose for the rendered project in DIR (project PROJECT)
docker_compose_in() {
	local dir="$1" project="$2" files=(-f "$1/compose.yaml") env="$1/.env"
	shift 2
	if [ -f "$dir/compose.arcpanel.yaml" ]; then
		files+=(-f "$dir/compose.arcpanel.yaml")
	fi
	if [ -f "$dir/.env.run" ]; then
		env="$dir/.env.run"
	fi
	"${DOCKER_ENV[@]}" docker compose --ansi never --progress plain -p "$project" --project-directory "$dir" \
		"${files[@]}" --env-file "$env" "$@"
}

DOCKER_SECRET_REFUSED='a secret it references holds quotes, $, ` or \ or more than 1024 characters'

# Whether VALUE can stand in the single quotes of a .env line
docker_secret_value_ok() {
	[ "${#1}" -le 1024 ] && [[ "$1" != *[\'\"\$\`\\]* ]] && [[ "$1" != *$'\n'* ]] && [[ "$1" != *$'\r'* ]]
}

# Write .env.run of USER's NAME (root 0600): its .env with the secrets it
# references resolved (E38). The .env keeps the references, so the panel,
# the copy in the home and backups never hold a value. Without references
# there is no .env.run and compose reads .env. Prints the reason on failure.
# dockerapp_dc writes it only for a command that starts containers and
# removes it right after.
dockerapp_env_run() {
	local user="$1" name="$2" rdir line key value resolved out='' rc
	rdir=$(dockerapp_rdir "$user" "$name")
	if ! grep -qF '{{secret:' "$rdir/.env" 2> /dev/null; then
		rm -f "$rdir/.env.run"
		return 0
	fi
	while IFS= read -r line || [ -n "$line" ]; do
		if [[ "$line" == *'{{secret:'* ]] && [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=\'(.*)\'$ ]]; then
			key=${BASH_REMATCH[1]}
			value=${BASH_REMATCH[2]}
			if ! secret_store_ready; then
				echo "$key: the secret store is not set up"
				return "$E_NOTEXIST"
			fi
			secret_resolve_into "$user" "$value" resolved
			rc=$?
			if [ "$rc" -ne 0 ]; then
				echo "$key: $SECRET_REASON"
				return "$rc"
			fi
			if ! docker_secret_value_ok "$resolved"; then
				echo "$key: $DOCKER_SECRET_REFUSED"
				return "$E_INVALID"
			fi
			line="$key='$resolved'"
		fi
		out+="$line"$'\n'
	done < "$rdir/.env"
	(umask 077 && printf '%s' "$out" > "$rdir/.env.run.tmp") && mv -f "$rdir/.env.run.tmp" "$rdir/.env.run"
}

# docker compose for USER's NAME. A command that starts containers gets the
# secrets of the .env resolved for its run and stops when one cannot be; the
# other commands (ps, logs, down, config, …) read the .env with its
# references, so no value is decrypted or left on disk for them.
dockerapp_dc() {
	local user="$1" name="$2" rdir out rc
	shift 2
	rdir=$(dockerapp_rdir "$user" "$name")
	case "$1" in
		up | create | start | restart | run)
			out=$(dockerapp_env_run "$user" "$name")
			rc=$?
			if [ "$rc" -ne 0 ]; then
				rm -f "$rdir/.env.run"
				echo "$out"
				return "$rc"
			fi
			docker_compose_in "$rdir" "$(dockerapp_project "$user" "$name")" "$@"
			rc=$?
			rm -f "$rdir/.env.run"
			return "$rc"
			;;
	esac
	rm -f "$rdir/.env.run"
	docker_compose_in "$rdir" "$(dockerapp_project "$user" "$name")" "$@"
}

# Write compose.arcpanel.yaml in DIR for the services of the catalog file:
# what ArcPanel enforces on every service (spec §5.4). JSON is YAML.
# PORTS is a JSON object {service: [{target, published, host_ip}]}.
docker_override_write() {
	local dir="$1" project="$2" user="$3" name="$4" uid="$5" gid="$6" cpus="$7" memory="$8" ports="$9" services
	services=$(rm -f "$dir/compose.arcpanel.yaml" && docker_compose_in "$dir" "$project" config --services 2> /dev/null) || return 1
	jq -n --arg services "$services" --arg user "$user" --arg name "$name" --arg uid "$uid" --arg gid "$gid" \
		--arg cpus "$cpus" --arg memory "${memory}m" --argjson ports "$ports" '
		{ services: ($services | split("\n") | map(select(length > 0)) | map({ key: ., value: ({
			user: "\($uid):\($gid)",
			cap_drop: ["ALL"],
			security_opt: ["no-new-privileges:true"],
			cgroup_parent: "user-\($uid).slice",
			cpus: ($cpus | tonumber),
			mem_limit: $memory,
			restart: "unless-stopped",
			logging: { driver: "json-file", options: { "max-size": "10m", "max-file": "3" } },
			labels: { "top.arcpanel.user": $user, "top.arcpanel.app": $name }
		} + (if $ports[.] then { ports: ($ports[.] | map(. + { protocol: "tcp", mode: "ingress" })) } else {} end)) }) | from_entries) }' \
		> "$dir/compose.arcpanel.yaml.tmp" || return 1
	chmod 600 "$dir/compose.arcpanel.yaml.tmp"
	mv -f "$dir/compose.arcpanel.yaml.tmp" "$dir/compose.arcpanel.yaml"
}

# Reasons why the merged configuration CONFIG (docker compose config json)
# of an app of USER (UID:GID) is refused, one per line (spec §5.4). DATA is
# the data directory, IMAGES the manifest images as a JSON array, PORTS the
# allowed published ports as a JSON array of {published, host_ip}.
docker_config_findings() {
	local config="$1" uid="$2" gid="$3" data="$4" images="$5" ports="$6"
	jq -r --arg user "$uid:$gid" --arg slice "user-$uid.slice" --arg data "$data/" \
		--argjson images "$images" --argjson ports "$ports" '
		def forbidden: ["privileged", "cap_add", "devices", "device_cgroup_rules", "network_mode", "pid", "ipc", "uts",
			"userns_mode", "cgroup", "build", "volumes_from", "sysctls", "runtime", "isolation", "env_file", "label_file",
			"group_add", "credential_spec", "use_api_socket", "gpus", "models", "provider"];
		[
			(.services | to_entries[] | .key as $s | .value as $v
				| (forbidden[] as $k | select($v[$k] != null and $v[$k] != false and $v[$k] != [] and $v[$k] != {}) | "\($s): \($k) is not allowed"),
				(if $v.user != $user then "\($s): must run as \($user)" else empty end),
				(if ($v.cap_drop // []) | index("ALL") == null then "\($s): cap_drop must contain ALL" else empty end),
				(if ($v.security_opt // []) != ["no-new-privileges:true"] then "\($s): security_opt must be no-new-privileges only" else empty end),
				(if $v.cgroup_parent != $slice then "\($s): cgroup_parent must be \($slice)" else empty end),
				(if ($images | index($v.image)) == null then "\($s): image \($v.image) is not in the manifest" else empty end),
				(($v.extra_hosts // {}) | if type == "object" then to_entries[] | select(.key != "host.docker.internal" or ((.value | tostring) | test("host-gateway") | not))
					| "\($s): extra host \(.key) is not allowed" else .[] | select(test("^host\\.docker\\.internal[:=]host-gateway$") | not) | "\($s): extra host \(.) is not allowed" end),
				(($v.volumes // [])[] | select(.type != "bind" and .type != "tmpfs") | "\($s): volume type \(.type) is not allowed (bind mounts in data/ only)"),
				(($v.volumes // [])[] | select(.type == "bind") | select((.source | startswith($data) | not) or (.source | test("/\\.\\.(/|$)")) or (.source | test("docker\\.sock")))
					| "\($s): bind mount \(.source) is outside \($data)"),
				(($v.ports // [])[] | . as $p | select([$ports[] | select((.published | tostring) == ($p.published | tostring) and .host_ip == ($p.host_ip // "0.0.0.0"))] | length == 0)
					| "\($s): published port \($p.host_ip // "0.0.0.0"):\($p.published) is not an app port")),
			((.networks // {}) | to_entries[] | select(.value.external == true or ((.value.driver // "bridge") != "bridge")) | "network \(.key) must be a bridge of the project"),
			((.volumes // {}) | keys[] | "named volume \(.) is not allowed"),
			((.secrets // {}) | keys[] | "secret \(.) is not allowed"),
			((.configs // {}) | keys[] | "config \(.) is not allowed")
		] | .[]' <<< "$config" 2>&1
}

# Render the project of an app into DIR and check it. Arguments: DIR
# PROJECT USER NAME SLUG VERSION CPUS MEMORY PORTS_SPEC, where PORTS_SPEC
# lists "portname:hostport:public" words. compose.yaml is copied from the
# catalog; .env must exist in DIR. Prints the reasons and returns
# E_FORBIDEN when the result is refused.
docker_render() {
	local dir="$1" project="$2" user="$3" name="$4" slug="$5" version="$6" cpus="$7" memory="$8" spec="$9"
	local manifest uid gid ports='{}' allowed='[]' word pname hport public service container host_ip config findings images
	manifest=$(docker_manifest "$slug" "$version")
	if [ ! -f "$manifest" ]; then
		echo "version $version of $slug is not in the catalog"
		return "$E_NOTEXIST"
	fi
	uid=$(docker_uid "$user") || return "$E_NOTEXIST"
	gid=$(docker_gid "$user")
	install -m 600 "$(docker_app_dir "$slug" "$version")/compose.yaml" "$dir/compose.yaml"
	for word in $spec; do
		IFS=: read -r pname hport public <<< "$word"
		service=$(jq -r --arg p "$pname" '.ports[$p].service' "$manifest")
		container=$(jq -r --arg p "$pname" '.ports[$p].container' "$manifest")
		host_ip=127.0.0.1
		if [ "$public" = 'yes' ]; then
			host_ip=0.0.0.0
		fi
		ports=$(jq -c --arg s "$service" --argjson t "$container" --arg h "$hport" --arg ip "$host_ip" \
			'.[$s] += [{ target: $t, published: $h, host_ip: $ip }]' <<< "$ports")
		allowed=$(jq -c --arg h "$hport" --arg ip "$host_ip" '. + [{ published: $h, host_ip: $ip }]' <<< "$allowed")
	done
	if ! docker_override_write "$dir" "$project" "$user" "$name" "$uid" "$gid" "$cpus" "$memory" "$ports"; then
		echo "the Compose file of $slug $version could not be read"
		return "$E_FORBIDEN"
	fi
	config=$(docker_compose_in "$dir" "$project" config --no-env-resolution --format json 2>&1) || {
		printf '%s\n' "$config" | tail -n3
		return "$E_FORBIDEN"
	}
	images=$(jq -c '.images' "$manifest")
	findings=$(docker_config_findings "$config" "$uid" "$gid" "$(dockerapp_data_dir "$user" "$name")" "$images" "$allowed")
	# every port of the manifest must name a service of the project
	findings+=$(jq -r --argjson c "$config" '(.ports // {}) | to_entries[] | select($c.services[.value.service] == null)
		| "\nport \(.key): service \(.value.service) is not in compose.yaml"' "$manifest")
	findings+=$(jq -r --argjson c "$config" '(.dumps // [])[] | select($c.services[.service] == null)
		| "\ndump: service \(.service) is not in compose.yaml"' "$manifest")
	findings=$(printf '%s\n' "$findings" | grep .)
	if [ -n "$findings" ]; then
		printf '%s\n' "$findings"
		return "$E_FORBIDEN"
	fi
	return 0
}

# PORTS_SPEC of USER's NAME from its record and the registry
dockerapp_ports_spec() {
	local user="$1" name="$2" line web extra pair pname port public spec=''
	line=$(dockerapp_line "$user" "$name")
	web=$(nodeapp_field "$line" WEB_PORT)
	extra=$(nodeapp_field "$line" EXTRA_PORTS)
	if [ -n "$web" ]; then
		spec="web:$web:$(dockerapp_port_public "$user" "$web")"
	fi
	for pair in ${extra//,/ }; do
		pname=${pair%%:*}
		port=${pair#*:}
		public=$(dockerapp_port_public "$user" "$port")
		spec="$spec $pname:$port:$public"
	done
	echo "$spec"
}

# yes when PORT of USER is public in the registry
dockerapp_port_public() {
	local public
	public=$(ports_record "$1" "$2" | cut -d'|' -f5)
	echo "${public:-no}"
}

# Render USER's NAME from its record into its project directory. The .env
# must be there. Prints the reason on failure.
dockerapp_render() {
	local user="$1" name="$2" line
	line=$(dockerapp_line "$user" "$name")
	docker_render "$(dockerapp_rdir "$user" "$name")" "$(dockerapp_project "$user" "$name")" "$user" "$name" \
		"$(nodeapp_field "$line" SLUG)" "$(nodeapp_field "$line" VERSION)" "$(nodeapp_field "$line" CPUS)" \
		"$(nodeapp_field "$line" MEMORY)" "$(dockerapp_ports_spec "$user" "$name")"
}

# Create the data folders of USER's NAME as the user and check every bind
# source of the rendered project (spec §5.4 step 4). Prints the reason and
# returns E_FORBIDEN.
dockerapp_binds_check() {
	local user="$1" name="$2" home data source real uid volume
	home=$(dockerapp_home "$user" "$name")
	data=$(dockerapp_data_dir "$user" "$name")
	uid=$(id -u "$user")
	if [ -L "$HOMEDIR/$user/apps" ] || [ -L "$home" ] || [ -L "$data" ]; then
		echo "$home or a folder above it is a symbolic link"
		return "$E_FORBIDEN"
	fi
	node_as_user "$user" bash -c 'umask 077; mkdir -p "$1"' mk "$data" || {
		echo "$data could not be created"
		return "$E_FORBIDEN"
	}
	for volume in $(jq -r '(.volumes // [])[].name' "$(docker_manifest "$(dockerapp_get "$user" "$name" SLUG)" "$(dockerapp_get "$user" "$name" VERSION)")"); do
		node_as_user "$user" bash -c 'umask 077; [ -L "$1" ] || mkdir -p "$1"' mk "$data/$volume" > /dev/null 2>&1
	done
	while IFS= read -r source; do
		[ -n "$source" ] || continue
		real=$(readlink -m "$source")
		if [ -L "$source" ] || [ ! -d "$source" ] || [ "$(stat -c %u "$source")" != "$uid" ] \
			|| [ "$real" != "$source" ] || [[ "$real" != "$data"/* ]]; then
			echo "bind source $source must be a folder of $user inside $data, not a link"
			return "$E_FORBIDEN"
		fi
	done < <(dockerapp_dc "$user" "$name" config --format json 2> /dev/null | jq -r '.services[].volumes // [] | .[] | select(.type == "bind") | .source')
	return 0
}

#----------------------------------------------------------#
#                   Copies for backups                     #
#----------------------------------------------------------#

# Refresh the app.conf and .env copies of USER's NAME when its folder exists
dockerapp_copy_write() {
	local user="$1" name="$2" home
	home=$(dockerapp_home "$user" "$name")
	if [ ! -d "$home" ] || [ -L "$home" ]; then
		return 0
	fi
	dockerapp_line "$user" "$name" \
		| node_as_user "$user" bash -c 'umask 077; [ ! -L "$1" ] && cat > "$1.tmp" && mv -f "$1.tmp" "$1"' conf "$home/app.conf"
	if [ -f "$(dockerapp_rdir "$user" "$name")/.env" ]; then
		grep -v '^ARC_' "$(dockerapp_rdir "$user" "$name")/.env" \
			| node_as_user "$user" bash -c 'umask 077; [ ! -L "$1" ] && cat > "$1.tmp" && mv -f "$1.tmp" "$1"' env "$home/.env"
	fi
	return 0
}

# The app.conf copy of USER's NAME, empty when there is none
dockerapp_copy_line() {
	node_as_user "$1" bash -c '[ -f "$1" ] && [ ! -L "$1" ] && head -c 4096 "$1"' conf "$(dockerapp_home "$1" "$2")/app.conf" 2> /dev/null \
		| grep -m1 "^NAME='$2' "
}

#----------------------------------------------------------#
#                     Runtime state                        #
#----------------------------------------------------------#

# One JSON object per container of USER's apps (docker ps)
dockerapp_containers_json() {
	docker_cmd ps -a --filter "label=top.arcpanel.user=$1" --format '{{json .}}' 2> /dev/null \
		| jq -sc 'map({ id: .ID, app: (.Labels | split(",") | map(select(startswith("top.arcpanel.app="))) | .[0] // "" | ltrimstr("top.arcpanel.app=")),
			service: (.Labels | split(",") | map(select(startswith("com.docker.compose.service="))) | .[0] // "" | ltrimstr("com.docker.compose.service=")),
			image: .Image, state: .State, status: .Status, running_for: .RunningFor })'
}

# Docker state of an app from CONTAINERS: running (all running), partial,
# restarting, exited (none running) or missing (no container)
dockerapp_docker_state() {
	jq -r --arg a "$2" '[.[] | select(.app == $a)] as $c
		| if ($c | length) == 0 then "missing"
		elif ([$c[] | select(.state == "restarting")] | length) > 0 then "restarting"
		elif ([$c[] | select(.state == "running")] | length) == ($c | length) then "running"
		elif ([$c[] | select(.state == "running")] | length) > 0 then "partial"
		else "exited" end' <<< "$1"
}

# Whether USER's NAME should run: not suspended and not stopped by the user
dockerapp_should_run() {
	local line
	line=$(dockerapp_line "$1" "$2")
	[ "$(nodeapp_field "$line" SUSPENDED)" = 'no' ] && [ "$(nodeapp_field "$line" STATE)" = 'running' ] \
		&& [ "$(nodeapp_field "$line" STATUS)" != 'installing' ] && [ "$(nodeapp_field "$line" STATUS)" != 'updating' ]
}

# Wait for USER's NAME to pass its health check (manifest timeout); prints
# the reason and returns 1 when it does not
dockerapp_health() {
	local user="$1" name="$2" line manifest type timeout path expect port code deadline containers state
	line=$(dockerapp_line "$user" "$name")
	manifest=$(docker_manifest "$(nodeapp_field "$line" SLUG)" "$(nodeapp_field "$line" VERSION)")
	type=$(jq -r .healthcheck.type "$manifest")
	timeout=$(jq -r .healthcheck.timeout "$manifest")
	deadline=$(($(date +%s) + timeout))
	if [ "$type" = 'http' ]; then
		path=$(jq -r .healthcheck.path "$manifest")
		expect=$(jq -c .healthcheck.expect "$manifest")
		port=$(nodeapp_field "$line" WEB_PORT)
		while [ "$(date +%s)" -lt "$deadline" ]; do
			code=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$port$path" 2> /dev/null)
			if jq -e --argjson c "${code:-0}" 'index($c) != null' <<< "$expect" > /dev/null; then
				return 0
			fi
			containers=$(dockerapp_containers_json "$user")
			state=$(dockerapp_docker_state "$containers" "$name")
			if [ "$state" = 'exited' ] || [ "$state" = 'missing' ]; then
				echo "the containers stopped before the app answered"
				return 1
			fi
			sleep 2
		done
		echo "http://127.0.0.1:$port$path did not answer ${expect} within ${timeout}s (last status ${code:-none})"
		return 1
	fi
	while [ "$(date +%s)" -lt "$deadline" ]; do
		state=$(dockerapp_dc "$user" "$name" ps -a --format json 2> /dev/null | jq -sr 'flatten
			| if length == 0 then "missing"
			elif ([.[] | select(.State != "running")] | length) > 0 then (if ([.[] | select(.State == "exited" or .State == "dead")] | length) > 0 then "exited" else "starting" end)
			elif ([.[] | select(.Health == "unhealthy")] | length) > 0 then "unhealthy"
			elif ([.[] | select(.Health == "starting")] | length) > 0 then "starting"
			else "ok" end')
		case "$state" in
			ok) return 0 ;;
			exited | missing)
				echo "the containers stopped (state $state)"
				return 1
				;;
		esac
		sleep 2
	done
	echo "the containers were not healthy within ${timeout}s"
	return 1
}

# Write the last LINES lines of the app's container logs to stdout
dockerapp_log_tail() {
	dockerapp_dc "$1" "$2" logs --no-color --tail "${3:-50}" 2>&1
}

#----------------------------------------------------------#
#                         Tasks                            #
#----------------------------------------------------------#

dockerapp_task_conf() {
	echo "$(dockerapp_rdir "$1" "$2")/task.state"
}

dockerapp_task_log() {
	echo "$(dockerapp_rdir "$1" "$2")/task.log"
}

dockerapp_task_get() {
	local file
	file=$(dockerapp_task_conf "$1" "$2")
	if [ -f "$file" ]; then
		grep -oE "(^| )$3='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

dockerapp_task_set() {
	local user="$1" name="$2" file pair key line='' tmp
	local -A values=()
	shift 2
	for key in $DOCKERAPP_TASK_KEYS; do
		values[$key]=$(dockerapp_task_get "$user" "$name" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $DOCKERAPP_TASK_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	file=$(dockerapp_task_conf "$user" "$name")
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 600 "$tmp"
	mv -f "$tmp" "$file"
}

docker_task_alive() {
	local pid="$1"
	[[ "$pid" =~ ^[0-9]+$ ]] && [ -r "/proc/$pid/cmdline" ] \
		&& tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q "$2"
}

# Mark a running task whose process is gone as failed
dockerapp_task_reap() {
	local user="$1" name="$2"
	if [ "$(dockerapp_task_get "$user" "$name" STATUS)" = 'running' ] \
		&& ! docker_task_alive "$(dockerapp_task_get "$user" "$name" PID)" v-run-docker-app-task; then
		dockerapp_task_set "$user" "$name" STATUS=failed "END=$(date +'%F %T')"
	fi
}

dockerapp_task_running() {
	[ -f "$(dockerapp_task_conf "$1" "$2")" ] || return 1
	dockerapp_task_reap "$1" "$2"
	[ "$(dockerapp_task_get "$1" "$2" STATUS)" = 'running' ]
}

# Exit with E_INUSE while a task of USER's NAME runs (unless called from
# that task)
dockerapp_check_no_task() {
	if [ "$ARC_DOCKER_TASK" = "$1/$2" ]; then
		return 0
	fi
	if dockerapp_task_running "$1" "$2"; then
		check_result "$E_INUSE" "a task of the Docker app $2 is running ($(dockerapp_task_get "$1" "$2" ACTION))"
	fi
}

# Task log from byte OFFSET as JSON (at most 256 KB), with the next offset
docker_task_json() {
	local conf="$1" log="$2" offset="$3" size=0 text='' get
	if [ -f "$log" ]; then
		size=$(stat -c %s "$log")
	fi
	if [ "$offset" -gt "$size" ]; then
		offset=0
	fi
	if [ "$size" -gt "$offset" ]; then
		text=$(tail -c +"$((offset + 1))" "$log" | head -c 262144 | sed -e 's/\x1b\[[0-9;?]*[A-Za-z]//g' -e 's/.*\r//')
		offset=$((offset + $(tail -c +"$((offset + 1))" "$log" | head -c 262144 | wc -c)))
	fi
	get() {
		if [ -f "$conf" ]; then
			grep -oE "(^| )$1='[^']*'" "$conf" | head -n1 | cut -d\' -f2
		fi
	}
	jq -nc --arg id "$(get ID)" --arg action "$(get ACTION)" --arg args "$(get ARGS)" --arg status "$(get STATUS)" \
		--arg exit "$(get EXIT)" --arg start "$(get START)" --arg end "$(get END)" --arg log "$text" --arg offset "$offset" \
		'{ ID: $id, ACTION: $action, ARGS: $args, STATUS: $status, EXIT: $exit, START: $start, END: $end, LOG: $log, OFFSET: $offset }'
}

#----------------------------------------------------------#
#                 Templates and domains                    #
#----------------------------------------------------------#

# Exit unless USER's DOMAIN can take a Docker app (EXCEPT: the app that has
# it already)
dockerapp_check_domain() {
	local user="$1" domain="$2" except="$3" other
	if [ -z "$(arc_tpl_active_kind)" ]; then
		check_result "$E_INVALID" "Docker apps need NGINX as web server or proxy"
	fi
	if ! nodeapp_tpl_exists "$DOCKERAPP_TEMPLATE"; then
		check_result "$E_NOTEXIST" "template $DOCKERAPP_TEMPLATE is not installed"
	fi
	if nodeapp_exists "$user" "$domain"; then
		check_result "$E_EXISTS" "$domain has a Node.js app"
	fi
	other=$(dockerapp_of_domain "$user" "$domain")
	if [ -n "$other" ] && [ "$other" != "$except" ]; then
		check_result "$E_EXISTS" "$domain has the Docker app $other"
	fi
}

# Bind USER's NAME to its DOMAIN: template and BACKEND_PORT (records
# PREV_TPL when MODE is new). Prints the reason on failure.
dockerapp_domain_bind() {
	local user="$1" name="$2" domain="$3" port="$4" out
	if [ "$(arc_tpl_active "$user" "$domain")" != "$DOCKERAPP_TEMPLATE" ]; then
		out=$(nodeapp_tpl_switch "$user" "$domain" "$DOCKERAPP_TEMPLATE" 2>&1) || {
			echo "template $DOCKERAPP_TEMPLATE could not be applied to $domain: ${out#Error: }"
			return "$E_RESTART"
		}
	fi
	if [ "$(arc_tplvars_get "$user" "$domain" BACKEND_PORT)" != "$port" ]; then
		out=$("$BIN/v-change-web-domain-tpl-vars" "$user" "$domain" "BACKEND_PORT=$port" 2>&1) || {
			echo "${out#Error: }"
			return "$E_RESTART"
		}
	elif ! grep -q "127\.0\.0\.1:$port;" "$HOMEDIR/$user/conf/web/$domain/nginx.conf" 2> /dev/null; then
		"$BIN/v-rebuild-web-domain" "$user" "$domain" > /dev/null 2>&1 || return "$E_RESTART"
	fi
	return 0
}

# Undo the binding of USER's DOMAIN: variables removed, PREV template back
dockerapp_domain_unbind() {
	local user="$1" domain="$2" prev="$3"
	if [ -z "$domain" ] || ! arc_tpl_domain_exists "$user" "$domain"; then
		return 0
	fi
	"$BIN/v-delete-web-domain-tpl-vars" "$user" "$domain" > /dev/null 2>&1
	if [ -z "$prev" ] || [ "$prev" = "$DOCKERAPP_TEMPLATE" ] || ! nodeapp_tpl_exists "$prev"; then
		prev=$(nodeapp_package_template "$user")
	fi
	if [ "$(arc_tpl_active "$user" "$domain")" != "$prev" ]; then
		# reload now: nothing else restarts the web server after the switch
		nodeapp_tpl_switch "$user" "$domain" "$prev" yes > /dev/null 2>&1
	fi
	return 0
}

#----------------------------------------------------------#
#                     Package limits                       #
#----------------------------------------------------------#

docker_pkg_key() {
	node_pkg_key "$1" "$2"
}

# Exit with E_DISABLED unless the package of USER has DOCKER='yes'
dockerapp_check_package() {
	if [ "$(docker_pkg_key "$1" DOCKER)" != 'yes' ]; then
		check_result "$E_DISABLED" "Docker apps are not included in the package of $1"
	fi
}

dockerapp_limit() {
	docker_pkg_key "$1" DOCKER_APPS
}

dockerapp_check_limit() {
	local limit
	limit=$(dockerapp_limit "$1")
	if [ "$limit" != 'unlimited' ] && [ "$(dockerapp_count "$1")" -ge "${limit:-0}" ]; then
		check_result "$E_LIMIT" "the package of $1 allows $limit Docker apps (DOCKER_APPS)"
	fi
}

# Highest memory (MB) of one app of USER: DOCKER_MEMORY, then MEMORY_LIMIT
dockerapp_max_memory() {
	local value limit
	value=$(docker_mb "$(docker_pkg_key "$1" DOCKER_MEMORY)") || value=''
	limit=$(nodeapp_memory_limit "$1")
	if [ -n "$limit" ] && { [ -z "$value" ] || [ "$limit" -lt "$value" ]; }; then
		value=$limit
	fi
	echo "${value:-$NODEAPP_MAX_MEMORY}"
}

# Highest CPUs of one app of USER: DOCKER_CPUS, else the number of CPUs
dockerapp_max_cpus() {
	local value
	value=$(docker_pkg_key "$1" DOCKER_CPUS)
	if docker_cpus_valid "$value"; then
		echo "$value"
	else
		nproc
	fi
}

# Exit unless CPUS and MEMORY (MB) fit the package of USER and the
# requirements of manifest FILE
dockerapp_check_limits() {
	local user="$1" cpus="$2" memory="$3" manifest="$4" max need
	if ! docker_cpus_valid "$cpus"; then
		check_result "$E_INVALID" "invalid CPUs :: $cpus (a number like 0.5)"
	fi
	if ! [[ "$memory" =~ ^[0-9]{2,7}$ ]] || [ "$memory" -lt 32 ]; then
		check_result "$E_INVALID" "invalid memory :: $memory (MB, at least 32)"
	fi
	max=$(dockerapp_max_cpus "$user")
	if docker_cpus_gt "$cpus" "$max"; then
		check_result "$E_LIMIT" "at most $max CPUs per app (DOCKER_CPUS)"
	fi
	max=$(dockerapp_max_memory "$user")
	if [ "$memory" -gt "$max" ]; then
		check_result "$E_LIMIT" "at most $max MB per app (DOCKER_MEMORY, MEMORY_LIMIT)"
	fi
	need=$(docker_mb "$(jq -r .requirements.memory "$manifest")")
	if [ "$memory" -lt "$need" ]; then
		check_result "$E_LIMIT" "the app needs at least $need MB of memory"
	fi
}

# Default CPUS and MEMORY (MB) of a new app of USER from manifest FILE,
# capped by the package; prints "CPUS MEMORY"
dockerapp_default_limits() {
	local user="$1" manifest="$2" cpus memory max
	cpus=$(jq -r .limits.cpus "$manifest")
	memory=$(docker_mb "$(jq -r .limits.memory "$manifest")")
	max=$(dockerapp_max_cpus "$user")
	if docker_cpus_gt "$cpus" "$max"; then
		cpus=$max
	fi
	max=$(dockerapp_max_memory "$user")
	if [ "$memory" -gt "$max" ]; then
		memory=$max
	fi
	echo "$cpus $memory"
}

#----------------------------------------------------------#
#                     Ports of apps                        #
#----------------------------------------------------------#

# Registry app id of port PNAME of USER's NAME
dockerapp_port_app() {
	if [ "$2" = 'web' ]; then
		echo "docker:$1"
	else
		echo "docker:$1.$2"
	fi
}

# Release every registry port of USER's NAME
dockerapp_ports_release() {
	local user="$1" name="$2" app
	for app in $(ports_read "$user" | awk -F'|' -v n="docker:$name" '$4 == n || index($4, n ".") == 1 { print $4 }' | sort -u); do
		"$BIN/v-release-user-port" "$user" "$app" > /dev/null 2>&1
	done
	return 0
}

# Make PORT (may be empty) the PNAME port of USER's NAME: the recorded port
# when it is free or already the app's (a restored user gets it back from
# the server range), else a new one when MODE is not keep. Prints the port.
dockerapp_port_ensure() {
	local user="$1" name="$2" pname="$3" port="$4" mode="$5" app out
	app=$(dockerapp_port_app "$name" "$pname")
	if [ -n "$port" ]; then
		if [ "$(ports_record "$user" "$port" | cut -d'|' -f4)" != "$app" ] \
			&& ! "$BIN/v-allocate-user-port" "$user" "$app" tcp "$port" > /dev/null 2>&1; then
			if ! "$BIN/v-add-user-port" "$user" "$port" no restored > /dev/null 2>&1 \
				|| ! "$BIN/v-allocate-user-port" "$user" "$app" tcp "$port" > /dev/null 2>&1; then
				port=''
			fi
		fi
	fi
	if [ -z "$port" ]; then
		port=$(ports_read "$user" | awk -F'|' -v a="$app" '$4 == a && $1 == $2 { print $1; exit }')
	fi
	if [ -z "$port" ] && [ "$mode" = 'keep' ]; then
		return 1
	fi
	if [ -z "$port" ]; then
		out=$("$BIN/v-allocate-user-port" "$user" "$app" 2>&1) || {
			echo "${out#Error: }"
			return "$E_LIMIT"
		}
		port=$(printf '%s\n' "$out" | tail -n1)
	fi
	echo "$port"
}

#----------------------------------------------------------#
#                       Lifecycle                          #
#----------------------------------------------------------#

# dockerapp_set under the lock of USER's records (tasks, hooks): commands
# that hold the lock already write directly
dockerapp_update() {
	local user="$1"
	if [ "$ARC_DOCKERAPPS_LOCKED" = "$user" ]; then
		dockerapp_set "$@"
		return
	fi
	(
		exec {fd}> "$ARCPANEL/data/users/$user/dockerapps.lock"
		flock -w 120 "$fd" || exit "$E_LA"
		dockerapp_set "$@"
	)
}

# Exit unless USER has the app NAME, not suspended unless MODE is any
dockerapp_check_app() {
	if ! dockerapp_name_valid "$2" || ! dockerapp_exists "$1" "$2"; then
		check_result "$E_NOTEXIST" "$1 has no Docker app $2"
	fi
	if [ "$3" != 'any' ] && [ "$(dockerapp_get "$1" "$2" SUSPENDED)" != 'no' ]; then
		check_result "$E_SUSPENDED" "the Docker app $2 is suspended"
	fi
}

# Exit while USER's NAME is installing or updating
dockerapp_check_settled() {
	local status
	status=$(dockerapp_get "$1" "$2" STATUS)
	if { [ "$status" = 'installing' ] || [ "$status" = 'updating' ]; } && [ "$ARC_DOCKER_TASK" != "$1/$2" ]; then
		check_result "$E_INUSE" "the Docker app $2 is $status"
	fi
}

# Render, check and start USER's NAME (containers recreated where their
# configuration changed). Prints the reason on failure.
dockerapp_up() {
	local user="$1" name="$2" out
	out=$(dockerapp_render "$user" "$name") || {
		echo "$out"
		return "$E_FORBIDEN"
	}
	out=$(dockerapp_binds_check "$user" "$name") || {
		echo "$out"
		return "$E_FORBIDEN"
	}
	out=$(dockerapp_dc "$user" "$name" up -d --remove-orphans --quiet-pull 2>&1) || {
		printf '%s\n' "$out" | grep -v '^ *$' | tail -n 5
		return "$E_RESTART"
	}
	return 0
}

# Re-apply a changed setting: start USER's NAME again when it runs, else
# only render it
dockerapp_apply() {
	local user="$1" name="$2" out containers state
	containers=$(dockerapp_containers_json "$user")
	state=$(dockerapp_docker_state "$containers" "$name")
	if [ "$state" != 'missing' ] && [ "$state" != 'exited' ]; then
		out=$(dockerapp_up "$user" "$name")
		check_result $? "$out"
	else
		out=$(dockerapp_render "$user" "$name")
		check_result $? "$out"
	fi
}

# Install task: pull, start and wait for the health check; output goes to
# the task log
dockerapp_task_install() {
	local user="$1" name="$2" out post
	dockerapp_update "$user" "$name" STATUS=installing REASON=
	echo "== Checking the project"
	if ! out=$(dockerapp_render "$user" "$name") || ! out=$(dockerapp_binds_check "$user" "$name"); then
		echo "$out"
		dockerapp_update "$user" "$name" STATUS=failed REASON=render
		return "$E_FORBIDEN"
	fi
	echo "== Pulling the images"
	if ! dockerapp_dc "$user" "$name" pull 2>&1; then
		dockerapp_update "$user" "$name" STATUS=failed REASON=pull
		return "$E_CONNECT"
	fi
	echo "== Starting the containers"
	if ! dockerapp_dc "$user" "$name" up -d --remove-orphans 2>&1; then
		dockerapp_update "$user" "$name" STATUS=failed REASON=start
		return "$E_RESTART"
	fi
	echo "== Waiting for the health check"
	if ! out=$(dockerapp_health "$user" "$name"); then
		echo "$out"
		echo "== Last log lines"
		dockerapp_log_tail "$user" "$name" 50
		dockerapp_update "$user" "$name" STATUS=failed REASON=health
		return "$E_RESTART"
	fi
	dockerapp_update "$user" "$name" STATUS=running REASON=
	post=$(jq -r '.post_install // ""' "$(docker_manifest "$(dockerapp_get "$user" "$name" SLUG)" "$(dockerapp_get "$user" "$name" VERSION)")")
	post=$(docker_interpolate "$post" "$user" "$(dockerapp_get "$user" "$name" DOMAIN)")
	"$BIN/v-add-user-notification" "$user" "Docker app $name is ready" \
		"$(jq -r .name "$(docker_manifest "$(dockerapp_get "$user" "$name" SLUG)" "$(dockerapp_get "$user" "$name" VERSION)")") is running. $post" \
		> /dev/null 2>&1
	echo "== The app is running"
	return 0
}

# Start a task of USER's NAME detached from the caller; prints the task ID
dockerapp_task_start() {
	local user="$1" name="$2" action="$3" arg="$4" lock id log
	exec {lock}> "$(dockerapp_rdir "$user" "$name")/task.lock"
	flock -x "$lock"
	if dockerapp_task_running "$user" "$name"; then
		flock -u "$lock"
		check_result "$E_INUSE" "a task of the Docker app $name is running ($(dockerapp_task_get "$user" "$name" ACTION))"
	fi
	id="$(date +%s)$(printf '%04d' $((RANDOM % 10000)))"
	log=$(dockerapp_task_log "$user" "$name")
	: > "$log"
	chmod 600 "$log"
	dockerapp_task_set "$user" "$name" "ID=$id" "ACTION=$action" "ARGS=$arg" STATUS=running PID= EXIT= \
		"START=$(date +'%F %T')" END=
	# Detached: new session, no descriptor shared with the caller, so the
	# panel's request returns at once and the task survives it; the lock of
	# the records (held by v-add-docker-app) is not passed on
	if [ -n "$DOCKERAPP_LOCK_FD" ]; then
		setsid "$BIN/v-run-docker-app-task" "$id" "$user" "$name" "$action" $arg \
			< /dev/null >> "$log" 2>&1 {lock}>&- {DOCKERAPP_LOCK_FD}>&- &
	else
		setsid "$BIN/v-run-docker-app-task" "$id" "$user" "$name" "$action" $arg \
			< /dev/null >> "$log" 2>&1 {lock}>&- &
	fi
	dockerapp_task_set "$user" "$name" "PID=$!"
	flock -u "$lock"
	echo "$id"
}

#----------------------------------------------------------#
#                         Output                           #
#----------------------------------------------------------#

# CPU (percent) and memory (MB) of every container of USER as a JSON array
# of {id, cpu, memory}
dockerapp_stats_json() {
	local ids
	ids=$(docker_cmd ps -q --filter "label=top.arcpanel.user=$1" 2> /dev/null)
	if [ -z "$ids" ]; then
		echo '[]'
		return 0
	fi
	# shellcheck disable=SC2086
	docker_cmd stats --no-stream --format '{{json .}}' $ids 2> /dev/null | jq -sc '
		def mb: capture("^(?<n>[0-9.]+)(?<u>[A-Za-z]*)") as $m | ($m.n | tonumber)
			* ({ "B": (1 / 1048576), "KiB": (1 / 1024), "kB": (1 / 1024), "MiB": 1, "MB": 1, "GiB": 1024, "GB": 1024 }[$m.u] // 1);
		map({ id: .ID[0:12], cpu: (.CPUPerc | rtrimstr("%") | tonumber? // 0),
			memory: ((.MemUsage | split(" / ")[0]) | mb? // 0) })'
}

# The apps of USER as a JSON array: records with the manifest name, the
# latest version, Docker state, CPU and memory
dockerapp_rows_json() {
	local user="$1" containers stats name line slug version manifest latest app url ssl domain update
	containers=$(dockerapp_containers_json "$user")
	stats=$(dockerapp_stats_json "$user")
	for name in $(dockerapp_names "$user"); do
		line=$(dockerapp_line "$user" "$name")
		slug=$(nodeapp_field "$line" SLUG)
		version=$(nodeapp_field "$line" VERSION)
		domain=$(nodeapp_field "$line" DOMAIN)
		manifest=$(docker_manifest "$slug" "$version")
		app=''
		if [ -f "$manifest" ]; then
			app=$(jq -r .name "$manifest")
		fi
		latest=$(docker_latest_version "$slug")
		url=$(dockerapp_url "$user" "$domain")
		ssl=$(arc_tpl_domain_value "$user" "$domain" SSL)
		update=no
		if [ -n "$latest" ] && docker_version_newer "$latest" "$version"; then
			update=yes
		fi
		jq -nc --arg line "$line" --arg app "$app" --arg latest "$latest" --arg url "$url" --arg ssl "${ssl:-no}" \
			--arg update "$update" \
			--argjson containers "$containers" --argjson stats "$stats" '
			($line | [scan("([A-Z_]+)='"'"'([^'"'"']*)'"'"'")] | map({ key: .[0], value: .[1] }) | from_entries) as $r
			| [ $containers[] | select(.app == $r.NAME) ] as $c
			| ([ $c[].id ]) as $ids
			| ([ $stats[] | select(.id as $i | $ids | index($i)) ]) as $s
			| (if ($c | length) == 0 then "missing"
				elif ([ $c[] | select(.state == "restarting") ] | length) > 0 then "restarting"
				elif ([ $c[] | select(.state == "running") ] | length) == ($c | length) then "running"
				elif ([ $c[] | select(.state == "running") ] | length) > 0 then "partial"
				else "exited" end) as $docker
			| $r + {
				APP_NAME: (if $app == "" then $r.SLUG else $app end),
				LATEST: $latest,
				UPDATE: $update,
				URL: $url, SSL: $ssl, DOCKER_STATE: $docker,
				STATUS: (if $r.SUSPENDED != "no" then "suspended"
					elif $r.STATUS == "installing" or $r.STATUS == "updating" then $r.STATUS
					elif $r.STATUS == "failed" and $docker != "running" then "failed"
					elif $docker == "running" then "running"
					elif $docker == "partial" then "degraded"
					elif $docker == "restarting" then "restarting"
					elif $r.STATE == "stopped" then "stopped"
					else $docker end),
				CONTAINERS: ($c | length | tostring),
				RUNNING: ([ $c[] | select(.state == "running") ] | length | tostring),
				CPU: ([ $s[].cpu ] | add // 0 | . * 100 | round / 100 | tostring),
				MEM: ([ $s[].memory ] | add // 0 | round | tostring)
			}'
	done | jq -sc .
}

# Run a function or command with a time limit of SECONDS: TERM, then KILL
# 30 seconds later. Returns 124 when the limit was reached.
docker_with_timeout() {
	local seconds="$1" pid watcher rc start
	shift
	start=$(date +%s)
	"$@" &
	pid=$!
	(
		sleep "$seconds"
		kill -TERM "$pid" 2> /dev/null
		sleep 30
		kill -KILL "$pid" 2> /dev/null
	) 2> /dev/null &
	watcher=$!
	wait "$pid"
	rc=$?
	pkill -P "$watcher" 2> /dev/null
	kill "$watcher" 2> /dev/null
	wait "$watcher" 2> /dev/null
	if [ $(($(date +%s) - start)) -ge "$seconds" ]; then
		return 124
	fi
	return "$rc"
}

#----------------------------------------------------------#
#                  Updates and rollback                    #
#----------------------------------------------------------#

dockerapp_snapshot_file() {
	echo "$(dockerapp_home "$1" "$2")/.snapshots/$3.tar.zst"
}

# Size of the data folder of USER's NAME in MB
dockerapp_data_mb() {
	node_as_user "$1" du -sm "$(dockerapp_data_dir "$1" "$2")" 2> /dev/null | awk '{ print $1 }'
}

# Replace the snapshots of USER's NAME with one of its data folder for
# VERSION (as the user)
dockerapp_snapshot_take() {
	local user="$1" name="$2" version="$3" home
	home=$(dockerapp_home "$user" "$name")
	node_as_user "$user" bash -c 'umask 077; [ ! -L "$1" ] && [ ! -L "$1/.snapshots" ] && mkdir -p "$1/.snapshots" \
		&& rm -f "$1"/.snapshots/*.tar.zst && tar -C "$1" --zstd -cf "$2.tmp" data && mv -f "$2.tmp" "$2"' \
		snap "$home" "$(dockerapp_snapshot_file "$user" "$name" "$version")"
}

# Put the data folder of USER's NAME back from the snapshot of VERSION
dockerapp_snapshot_restore() {
	local user="$1" name="$2" version="$3" home
	home=$(dockerapp_home "$user" "$name")
	node_as_user "$user" bash -c '[ -f "$2" ] && [ ! -L "$2" ] && rm -rf "$1/data" && tar -C "$1" --zstd -xf "$2"' \
		snap "$home" "$(dockerapp_snapshot_file "$user" "$name" "$version")"
}

# Render VERSION of USER's NAME (variables kept, new ones get their
# defaults; the current .env is saved as .env.prev when SAVE is yes)
dockerapp_render_version() {
	local user="$1" name="$2" version="$3" save="$4" rdir line out
	rdir=$(dockerapp_rdir "$user" "$name")
	line=$(dockerapp_line "$user" "$name")
	if [ "$save" = 'yes' ]; then
		cp -p "$rdir/.env" "$rdir/.env.prev"
	fi
	out=$(dockerapp_env_render "$(docker_manifest "$(nodeapp_field "$line" SLUG)" "$version")" "$user" "$name" \
		"$(nodeapp_field "$line" DOMAIN)" /dev/null "$rdir/.env" "$rdir/.env" keep "$(dockerapp_ports_spec "$user" "$name")") || {
		echo "$out"
		return "$E_INVALID"
	}
	dockerapp_update "$user" "$name" "VERSION=$version"
	dockerapp_render "$user" "$name"
}

# Pull, start and check USER's NAME as rendered; output to the task log
dockerapp_task_start_rendered() {
	local user="$1" name="$2" out
	out=$(dockerapp_binds_check "$user" "$name") || {
		echo "$out"
		return "$E_FORBIDEN"
	}
	echo "== Pulling the images"
	dockerapp_dc "$user" "$name" pull 2>&1 || return "$E_CONNECT"
	echo "== Starting the containers"
	dockerapp_dc "$user" "$name" up -d --remove-orphans 2>&1 || return "$E_RESTART"
	echo "== Waiting for the health check"
	if ! out=$(dockerapp_health "$user" "$name"); then
		echo "$out"
		echo "== Last log lines"
		dockerapp_log_tail "$user" "$name" 50
		return "$E_RESTART"
	fi
	return 0
}

# Update task of USER's NAME to VERSION (spec D9)
dockerapp_task_update() {
	local user="$1" name="$2" version="$3" old status size free rc out
	old=$(dockerapp_get "$user" "$name" VERSION)
	status=$(dockerapp_get "$user" "$name" STATUS)
	size=$(dockerapp_data_mb "$user" "$name")
	free=$(docker_free_mb "$(dockerapp_home "$user" "$name")")
	if [ -n "$free" ] && [ "$free" -lt $((${size:-0} * 2 + 64)) ]; then
		echo "Not enough free disk for the snapshot: the data uses ${size} MB, $free MB are free"
		return "$E_DISK"
	fi
	dockerapp_update "$user" "$name" STATUS=updating REASON=
	echo "== Stopping $old"
	dockerapp_dc "$user" "$name" stop --timeout 30 2>&1
	echo "== Snapshot of the data (${size:-0} MB)"
	if ! dockerapp_snapshot_take "$user" "$name" "$old"; then
		echo "The snapshot failed; starting $old again"
		dockerapp_dc "$user" "$name" up -d 2>&1
		dockerapp_update "$user" "$name" "STATUS=$status" REASON=
		return "$E_DISK"
	fi
	echo "== Rendering $version"
	if out=$(dockerapp_render_version "$user" "$name" "$version" yes); then
		dockerapp_task_start_rendered "$user" "$name"
		rc=$?
	else
		echo "$out"
		rc=$E_FORBIDEN
	fi
	if [ "$rc" -eq 0 ]; then
		dockerapp_update "$user" "$name" STATUS=running REASON= "PREV_VERSION=$old"
		echo "== $version is running; the data of $old is kept for a rollback"
		return 0
	fi
	echo "== The update failed: rolling back to $old"
	dockerapp_dc "$user" "$name" down --remove-orphans --timeout 30 2>&1
	dockerapp_snapshot_restore "$user" "$name" "$old"
	mv -f "$(dockerapp_rdir "$user" "$name")/.env.prev" "$(dockerapp_rdir "$user" "$name")/.env"
	if dockerapp_render_version "$user" "$name" "$old" no && dockerapp_task_start_rendered "$user" "$name"; then
		dockerapp_update "$user" "$name" STATUS=running REASON= PREV_VERSION=
		echo "== $old is running again"
	else
		dockerapp_update "$user" "$name" STATUS=failed REASON=update PREV_VERSION=
		echo "== $old did not start again"
	fi
	dockerapp_copy_write "$user" "$name"
	return "$E_UPDATE"
}

# Rollback task of USER's NAME to PREV_VERSION with the data of its snapshot
dockerapp_task_rollback() {
	local user="$1" name="$2" prev current rdir
	prev=$(dockerapp_get "$user" "$name" PREV_VERSION)
	current=$(dockerapp_get "$user" "$name" VERSION)
	rdir=$(dockerapp_rdir "$user" "$name")
	dockerapp_update "$user" "$name" STATUS=updating REASON=
	echo "== Removing the containers of $current"
	dockerapp_dc "$user" "$name" down --remove-orphans --timeout 30 2>&1
	echo "== Restoring the data of $prev"
	if ! dockerapp_snapshot_restore "$user" "$name" "$prev"; then
		echo "The snapshot could not be restored"
		dockerapp_update "$user" "$name" STATUS=failed REASON=rollback
		return "$E_UPDATE"
	fi
	if [ -f "$rdir/.env.prev" ]; then
		mv -f "$rdir/.env.prev" "$rdir/.env"
	fi
	echo "== Rendering $prev"
	if dockerapp_render_version "$user" "$name" "$prev" no && dockerapp_task_start_rendered "$user" "$name"; then
		dockerapp_update "$user" "$name" STATUS=running REASON= PREV_VERSION=
		node_as_user "$user" rm -f "$(dockerapp_snapshot_file "$user" "$name" "$prev")"
		dockerapp_copy_write "$user" "$name"
		echo "== $prev is running"
		return 0
	fi
	dockerapp_update "$user" "$name" STATUS=failed REASON=rollback
	return "$E_UPDATE"
}

#----------------------------------------------------------#
#                         Dumps                            #
#----------------------------------------------------------#

# Run the dumps of the manifest of USER's NAME into data/.dumps/ (as the
# user); prints one line per dump, returns E_DB when one failed
dockerapp_dump() {
	local user="$1" name="$2" manifest dir count i service command file failed=0 codes
	manifest=$(docker_manifest "$(dockerapp_get "$user" "$name" SLUG)" "$(dockerapp_get "$user" "$name" VERSION)")
	count=$(jq '(.dumps // []) | length' "$manifest" 2> /dev/null)
	if [ "${count:-0}" -eq 0 ]; then
		return 0
	fi
	dir="$(dockerapp_data_dir "$user" "$name")/.dumps"
	node_as_user "$user" bash -c 'umask 077; [ ! -L "$1" ] && mkdir -p "$1" && [ ! -L "$1" ]' dumps "$dir" || return "$E_FORBIDEN"
	for ((i = 0; i < count; i++)); do
		service=$(jq -r ".dumps[$i].service" "$manifest")
		command=$(jq -r ".dumps[$i].command" "$manifest")
		file=$(jq -r ".dumps[$i].file" "$manifest")
		dockerapp_dc "$user" "$name" exec -T "$service" sh -c "$command" 2> /dev/null \
			| node_as_user "$user" bash -c 'umask 077; [ ! -L "$1.tmp" ] && cat > "$1.tmp"' dump "$dir/$file"
		codes=("${PIPESTATUS[@]}")
		if [ "${codes[0]}" -eq 0 ] && [ "${codes[1]}" -eq 0 ]; then
			node_as_user "$user" mv -f "$dir/$file.tmp" "$dir/$file"
			echo "$name: $service dumped to data/.dumps/$file ($(node_as_user "$user" stat -c %s "$dir/$file") bytes)"
		else
			node_as_user "$user" rm -f "$dir/$file.tmp"
			echo "$name: the dump of $service failed"
			failed=1
		fi
	done
	if [ "$failed" -eq 0 ]; then
		date +'%F %T' | node_as_user "$user" bash -c 'umask 077; [ ! -L "$1" ] && cat > "$1"' last "$dir/.last"
		return 0
	fi
	return "$E_DB"
}

#----------------------------------------------------------#
#                Suspension and convergence                #
#----------------------------------------------------------#

# Stop the containers of USER's NAME when Docker is there
dockerapp_halt() {
	if command -v docker > /dev/null 2>&1 && [ -f "$(dockerapp_rdir "$1" "$2")/.env" ]; then
		dockerapp_dc "$1" "$2" stop --timeout 30 > /dev/null 2>&1
	fi
	return 0
}

# Bring USER's NAME to its wanted state after a change of SUSPENDED: start
# it when it should run
dockerapp_resume() {
	local user="$1" name="$2" out
	if dockerapp_should_run "$user" "$name" && command -v docker > /dev/null 2>&1; then
		if out=$(dockerapp_up "$user" "$name"); then
			dockerapp_update "$user" "$name" STATUS=running REASON=
		else
			dockerapp_update "$user" "$name" STATUS=failed REASON=start
			echo "$out"
			return "$E_RESTART"
		fi
	fi
	return 0
}

# Published ports of the containers of USER's NAME as "host_ip:port" words
dockerapp_published() {
	docker_cmd ps --filter "label=top.arcpanel.user=$1" --filter "label=top.arcpanel.app=$2" --format '{{.Ports}}' 2> /dev/null \
		| tr ',' '\n' | grep -oE '[0-9.]+:[0-9]+->' | sed 's/->//' | sort -u
}

# Whether the rendered bindings of USER's NAME differ from the public flags
# of its ports in the registry
dockerapp_public_mismatch() {
	local user="$1" name="$2" word pname port public want published
	published=$(dockerapp_published "$user" "$name")
	[ -n "$published" ] || return 1
	for word in $(dockerapp_ports_spec "$user" "$name"); do
		IFS=: read -r pname port public <<< "$word"
		want=127.0.0.1
		if [ "$public" = 'yes' ]; then
			want=0.0.0.0
		fi
		if ! grep -qx "$want:$port" <<< "$published"; then
			return 0
		fi
	done
	return 1
}
