#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Control Panel - Object storage on Silo (E44)                     #
#                                                                           #
# One Silo service per server, on 127.0.0.1:9310. Every account with        #
# object storage is one IAM user arc-<user> whose policy lists exactly its  #
# buckets; S3 keys are service accounts of that user. The flat files of     #
# the account are the record:                                               #
#   $ARCPANEL/data/users/<user>/buckets.conf   one line per bucket            #
#   $ARCPANEL/data/users/<user>/s3keys.conf    one line per S3 key (no secret)#
# mcli runs as root with Silo's root credentials from /etc/arcpanel/silo.env #
# in its environment only. See                                              #
# docs/features/specs/2026-10-19-003-e44-object-storage-silo-design.md.     #
#                                                                           #
#===========================================================================#

SILO_PORT=9310
SILO_CONSOLE_PORT=9311
SILO_ENV=/etc/arcpanel/silo.env
SILO_UNIT=arcpanel-silo
SILO_UNIT_FILE=/etc/systemd/system/arcpanel-silo.service
SILO_BIN=/usr/bin/silo
SILO_MCLI=/usr/local/bin/mcli
SILO_DIR="$ARCPANEL/data/silo"
SILO_DATA_DEFAULT=/srv/arcpanel-silo
SILO_ROOT_USER=arcpanel-root
SILO_KEYS_MAX=20
SILO_REGION=us-east-1
# Repairs in a row that must see a key differ before it is fixed (spec §8)
SILO_MISSES="$SILO_DIR/repair.misses"
SILO_MISS_LIMIT=3
# Buckets and keys of deleted accounts that Silo did not answer for
SILO_ORPHANS="$SILO_DIR/orphans.conf"

# Keys of a line of buckets.conf and of s3keys.conf, in order
SILO_BUCKET_KEYS=(BUCKET QUOTA VERSIONING USED OBJECTS SUSPENDED TIME DATE)
SILO_KEY_KEYS=(KEY NAME BUCKET ACCESS EXPIRES TIME DATE)

# Actions of an account on its buckets (spec D6), and of a read-only key
SILO_RW_ACTIONS='"s3:ListBucket","s3:ListBucketVersions","s3:ListBucketMultipartUploads","s3:GetBucketLocation","s3:GetBucketVersioning","s3:GetLifecycleConfiguration","s3:PutLifecycleConfiguration","s3:GetObject*","s3:PutObject*","s3:DeleteObject*","s3:AbortMultipartUpload","s3:ListMultipartUploadParts"'
SILO_RO_ACTIONS='"s3:ListBucket","s3:ListBucketVersions","s3:GetBucketLocation","s3:GetBucketVersioning","s3:GetObject","s3:GetObjectVersion","s3:GetObjectTagging","s3:GetObjectVersionTagging"'

#----------------------------------------------------------#
#                         Service                          #
#----------------------------------------------------------#

silo_installed() {
	[ "$OBJECT_STORAGE_SYSTEM" = 'silo' ] && [ -f "$SILO_ENV" ]
}

silo_require() {
	if ! silo_installed; then
		check_result "$E_DISABLED" "object storage is not installed on this server"
	fi
}

# mcli as root with the root credentials in its environment only, never in
# its arguments; --config-dir keeps its state below data/silo
silo_mcli() {
	local pass
	pass=$(sed -n 's/^MINIO_ROOT_PASSWORD=//p' "$SILO_ENV" 2> /dev/null)
	MC_HOST_arc="http://$SILO_ROOT_USER:$pass@127.0.0.1:$SILO_PORT" \
		"$SILO_MCLI" --config-dir "$SILO_DIR/mcli" --no-color --disable-pager "$@"
}

# Silo answers within 30 seconds: the S3 port, and the admin calls, which
# fail for a while after a start, before the IAM data is loaded
silo_wait() {
	local i
	for i in $(seq 1 30); do
		if [ "$(curl -s -o /dev/null -m 2 -w '%{http_code}' "http://127.0.0.1:$SILO_PORT/minio/health/live")" = '200' ] \
			&& silo_mcli admin user list arc --json > /dev/null 2>&1; then
			return 0
		fi
		sleep 1
	done
	return 1
}

# URL of the public endpoint: https when its domain has a certificate, empty
# without an endpoint
silo_endpoint_url() {
	if [ -z "$SILO_HOSTNAME" ]; then
		return 0
	fi
	if grep -q "^DOMAIN='$SILO_HOSTNAME' .*SSL='yes'" "$ARCPANEL/data/users/$ROOT_USER/web.conf" 2> /dev/null; then
		echo "https://$SILO_HOSTNAME"
	else
		echo "http://$SILO_HOSTNAME"
	fi
}

silo_running() {
	systemctl -q is-active "$SILO_UNIT" 2> /dev/null
}

# Fail with E_CONNECT when Silo does not answer
silo_require_running() {
	if ! silo_wait; then
		check_result "$E_CONNECT" "the object storage service does not answer (systemctl status $SILO_UNIT)"
	fi
}

# Serialise bucket names, policies and keys of the whole server
silo_lock() {
	mkdir -p "$SILO_DIR"
	chmod 700 "$SILO_DIR"
	exec 8> "$SILO_DIR/.lock"
	flock -w 60 8
	check_result $? "Unable to acquire the object storage lock (timeout after 60s)"
}

# Random secret of LENGTH letters and digits
silo_random() {
	local out=''
	while [ "${#out}" -lt "$1" ]; do
		out="$out$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
	done
	echo "${out:0:$1}"
}

#----------------------------------------------------------#
#                       Flat files                         #
#----------------------------------------------------------#

silo_buckets_file() {
	echo "$ARCPANEL/data/users/$1/buckets.conf"
}

silo_keys_file() {
	echo "$ARCPANEL/data/users/$1/s3keys.conf"
}

# Value of KEY in a KEY='value' line
silo_field() {
	local line="$1" key="$2"
	if [[ " $line" =~ \ $key=\'([^\']*)\' ]]; then
		echo "${BASH_REMATCH[1]}"
	fi
}

# The line of USER's BUCKET, empty when there is none
silo_bucket_line() {
	grep -m1 "^BUCKET='$2' " "$(silo_buckets_file "$1")" 2> /dev/null
}

# Bucket names of USER, one per line
silo_buckets() {
	sed -n "s/^BUCKET='\([^']*\)'.*/\1/p" "$(silo_buckets_file "$1")" 2> /dev/null
}

# The line of USER's key KEY, empty when there is none
silo_key_line() {
	grep -m1 "^KEY='$2' " "$(silo_keys_file "$1")" 2> /dev/null
}

silo_keys() {
	sed -n "s/^KEY='\([^']*\)'.*/\1/p" "$(silo_keys_file "$1")" 2> /dev/null
}

# Write FILE from KEY=value arguments in the order of the key list NAME,
# replacing the line whose first key equals the first value
silo_write_line() {
	local file="$1" list="$2" first value key line='' sep=''
	shift 2
	declare -A values=()
	for value in "$@"; do
		values[${value%%=*}]="${value#*=}"
	done
	local -n keys="$list"
	for key in "${keys[@]}"; do
		line="$line$sep$key='${values[$key]:-}'"
		sep=' '
	done
	first="${keys[0]}"
	touch "$file"
	{
		grep -v "^$first='${values[$first]}' " "$file"
		echo "$line"
	} > "$file.tmp"
	mv -f "$file.tmp" "$file"
	chmod 640 "$file"
	silo_chown_user_file "$file"
}

# Change KEY=value fields of the line whose first field is ID
silo_set_fields() {
	local file="$1" list="$2" id="$3" line pair key
	shift 3
	local -n keys="$list"
	line=$(grep -m1 "^${keys[0]}='$id' " "$file" 2> /dev/null)
	[ -n "$line" ] || return 1
	local args=()
	for key in "${keys[@]}"; do
		args+=("$key=$(silo_field "$line" "$key")")
	done
	for pair in "$@"; do
		key="${pair%%=*}"
		local i
		for i in "${!args[@]}"; do
			if [ "${args[$i]%%=*}" = "$key" ]; then
				args[i]="$pair"
			fi
		done
	done
	silo_write_line "$file" "$list" "${args[@]}"
}

silo_delete_line() {
	local file="$1" key="$2" id="$3"
	[ -f "$file" ] || return 0
	grep -v "^$key='$id' " "$file" > "$file.tmp"
	mv -f "$file.tmp" "$file"
	chmod 640 "$file"
	silo_chown_user_file "$file"
}

# root:user 0640, as the other records of the account
silo_chown_user_file() {
	local user
	user=$(basename "$(dirname "$1")")
	chown "root:$user" "$1" 2> /dev/null || true
}

#----------------------------------------------------------#
#                         Names                            #
#----------------------------------------------------------#

# S3 bucket names without dots (spec D7)
silo_bucket_name_valid() {
	local name="$1"
	[[ "$name" =~ ^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$ ]] || return 1
	case "$name" in
		xn--* | sthree-* | amzn-s3-demo-* | *-s3alias | *--ol-s3 | *--x-s3) return 1 ;;
	esac
	return 0
}

is_bucket_format_valid() {
	if ! silo_bucket_name_valid "$1"; then
		check_result "$E_INVALID" "invalid bucket name :: $1 (3-63 lower case letters, digits and hyphens, a letter or digit at both ends)"
	fi
}

# The account that owns bucket NAME, empty when none does
silo_bucket_owner() {
	local file
	for file in "$ARCPANEL"/data/users/*/buckets.conf; do
		[ -f "$file" ] || continue
		if grep -q "^BUCKET='$1' " "$file"; then
			basename "$(dirname "$file")"
			return 0
		fi
	done
}

silo_iam_user() {
	echo "arc-$1"
}

silo_policy_name() {
	echo "arc-user-$1"
}

# S3 key names: 1-32 letters, digits, space, _ . - (spec §7)
is_s3_key_name_valid() {
	if ! [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9\ _.-]{0,31}$ ]]; then
		check_result "$E_INVALID" "invalid S3 key name :: $1 (1-32 letters, digits, spaces, _ . -)"
	fi
}

#----------------------------------------------------------#
#                     Package limits                       #
#----------------------------------------------------------#

silo_pkg_value() {
	tenancy_pkg_get "$(tenancy_user_value "$1" PACKAGE)" "$2"
}

# Whether USER's package includes object storage
silo_allowed() {
	[ "$(silo_pkg_value "$1" OBJECT_STORAGE)" = 'yes' ]
}

# MB of a quota value (OBJECT_STORAGE_QUOTA), "unlimited" stays
silo_quota_mb() {
	tenancy_limit_value OBJECT_STORAGE_QUOTA "$1" || return 1
	echo "$REPLY"
}

# Sum of the bucket quotas of USER in MB, leaving out bucket EXCEPT
silo_quota_used() {
	local user="$1" except="$2" total=0 line name quota
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		name=$(silo_field "$line" BUCKET)
		[ "$name" = "$except" ] && continue
		quota=$(silo_field "$line" QUOTA)
		total=$((total + ${quota:-0}))
	done < <(cat "$(silo_buckets_file "$user")" 2> /dev/null)
	echo "$total"
}

# Whether USER may hold buckets of NEW MB more (leaving out bucket EXCEPT)
# and, when COUNT is yes, one bucket more; 1 with the reason in REPLY
silo_limits_allow() {
	local user="$1" new="$2" except="$3" count="$4" limit used buckets
	if [ "$count" = 'yes' ]; then
		limit=$(silo_pkg_value "$user" OBJECT_STORAGE_BUCKETS)
		buckets=$(silo_buckets "$user" | grep -c .)
		if [ "$limit" != 'unlimited' ] && [ "$((buckets + 1))" -gt "${limit:-0}" ]; then
			REPLY="the package allows $limit buckets (OBJECT_STORAGE_BUCKETS)"
			return 1
		fi
	fi
	limit=$(silo_quota_mb "$(silo_pkg_value "$user" OBJECT_STORAGE_QUOTA)") || limit=0
	if [ "$limit" != 'unlimited' ]; then
		used=$(silo_quota_used "$user" "$except")
		if [ "$((used + new))" -gt "$limit" ]; then
			REPLY="the bucket quotas would reach $((used + new)) MB; the package allows $limit MB (OBJECT_STORAGE_QUOTA)"
			return 1
		fi
	fi
	return 0
}

# E_LIMIT unless silo_limits_allow
silo_check_limits() {
	if ! silo_limits_allow "$@"; then
		check_result "$E_LIMIT" "$REPLY"
	fi
}

# Reseller allocation (ADR-0004, spec D8): the reseller's own buckets and
# bucket quotas plus the allocation of every customer whose package has
# object storage, with a customer on PACKAGE (leaving out EXCEPT), stay
# within the reseller's own package. Prints the key and returns E_LIMIT.
objstore_quota_check() {
	local reseller="$1" package="$2" except="$3" own customer pkg key limit total value
	own=$(tenancy_user_value "$reseller" PACKAGE)
	for key in OBJECT_STORAGE_BUCKETS OBJECT_STORAGE_QUOTA; do
		limit=$(tenancy_pkg_get "$own" "$key")
		if [ "$key" = 'OBJECT_STORAGE_QUOTA' ]; then
			limit=$(silo_quota_mb "$limit") || limit=0
		fi
		# A reseller without object storage cannot give any
		if [ "$(tenancy_pkg_get "$own" OBJECT_STORAGE)" != 'yes' ]; then
			limit=0
		fi
		[ "$limit" = 'unlimited' ] && continue
		if [ "$key" = 'OBJECT_STORAGE_BUCKETS' ]; then
			total=$(silo_buckets "$reseller" | grep -c .)
		else
			total=$(silo_quota_used "$reseller" '')
		fi
		local pkgs=()
		while read -r customer; do
			if [ -n "$customer" ] && [ "$customer" != "$except" ]; then
				pkgs+=("$(tenancy_user_value "$customer" PACKAGE)")
			fi
		done < <(tenancy_customers "$reseller")
		pkgs+=("$package")
		for pkg in "${pkgs[@]}"; do
			[ "$(tenancy_pkg_get "$pkg" OBJECT_STORAGE)" = 'yes' ] || continue
			value=$(tenancy_pkg_get "$pkg" "$key")
			if [ "$key" = 'OBJECT_STORAGE_QUOTA' ]; then
				value=$(silo_quota_mb "$value") || value=unlimited
			fi
			if [ "$value" = 'unlimited' ]; then
				echo "$key"
				return "$E_LIMIT"
			fi
			total=$((total + ${value:-0}))
		done
		if [ "$total" -gt "$limit" ]; then
			echo "$key"
			return "$E_LIMIT"
		fi
	done
	return 0
}

#----------------------------------------------------------#
#                   IAM users and policies                 #
#----------------------------------------------------------#

# Resources of USER's buckets as a JSON list; a resource no bucket can match
# when there is none (bucket names are lower case)
silo_resources_json() {
	local user="$1" name out='' sep=''
	while read -r name; do
		[ -n "$name" ] || continue
		out="$out$sep\"arn:aws:s3:::$name\",\"arn:aws:s3:::$name/*\""
		sep=','
	done < <(silo_buckets "$user")
	if [ -z "$out" ]; then
		out='"arn:aws:s3:::NONE"'
	fi
	echo "[$out]"
}

# Policy of USER's account (spec D6)
silo_policy_json() {
	printf '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":[%s],"Resource":%s}]}\n' \
		"$SILO_RW_ACTIONS" "$(silo_resources_json "$1")"
}

# Inline policy of a key limited to BUCKET or to read-only, empty for a key
# with the account's own rights (spec §7). The account's policy still
# applies on top: Silo grants a key only what both allow.
silo_key_policy_json() {
	local user="$1" bucket="$2" access="$3" actions resources
	if [ -z "$bucket" ] && [ "$access" != 'ro' ]; then
		return 0
	fi
	actions="$SILO_RW_ACTIONS"
	[ "$access" = 'ro' ] && actions="$SILO_RO_ACTIONS"
	if [ -n "$bucket" ]; then
		resources="[\"arn:aws:s3:::$bucket\",\"arn:aws:s3:::$bucket/*\"]"
	else
		resources='["arn:aws:s3:::*"]'
	fi
	printf '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":[%s],"Resource":%s}]}\n' "$actions" "$resources"
}

silo_iam_exists() {
	silo_mcli admin user info arc "$(silo_iam_user "$1")" > /dev/null 2>&1
}

# Bring the IAM user and the policy of USER in line with its flat files:
# removed without buckets and keys, else created, the policy written and
# attached, and disabled while the account is suspended
silo_sync_user() {
	local user="$1" iam policy tmp
	iam=$(silo_iam_user "$user")
	policy=$(silo_policy_name "$user")
	if [ -z "$(silo_buckets "$user")" ] && [ -z "$(silo_keys "$user")" ]; then
		if silo_iam_exists "$user"; then
			silo_mcli admin user remove arc "$iam" > /dev/null 2>&1
		fi
		silo_mcli admin policy remove arc "$policy" > /dev/null 2>&1
		return 0
	fi
	if ! silo_iam_exists "$user"; then
		# The secret of the IAM user is never used or kept; mcli reads it from
		# stdin, so it never shows in a process list
		silo_random 40 | silo_mcli admin user add arc "$iam" > /dev/null 2>&1 || return "$E_CONNECT"
	fi
	tmp=$(mktemp)
	silo_policy_json "$user" > "$tmp"
	silo_mcli admin policy create arc "$policy" "$tmp" > /dev/null 2>&1
	local rc=$?
	rm -f "$tmp"
	[ "$rc" -eq 0 ] || return "$E_CONNECT"
	if [ "$(silo_mcli admin user info arc "$iam" --json 2> /dev/null | jq -r '.policyName // ""')" != "$policy" ]; then
		silo_mcli admin policy attach arc "$policy" --user "$iam" > /dev/null 2>&1 || return "$E_CONNECT"
	fi
	if [ "$(tenancy_user_value "$user" SUSPENDED)" = 'yes' ]; then
		silo_mcli admin user disable arc "$iam" > /dev/null 2>&1
	else
		silo_mcli admin user enable arc "$iam" > /dev/null 2>&1
	fi
	return 0
}

#----------------------------------------------------------#
#                         Buckets                          #
#----------------------------------------------------------#

silo_bucket_exists() {
	silo_mcli stat "arc/$1" > /dev/null 2>&1
}

# Create a bucket in Silo with its quota (MB) and versioning
silo_bucket_create() {
	local name="$1" quota="$2" versioning="$3"
	silo_bucket_exists "$name" || silo_mcli mb "arc/$name" > /dev/null 2>&1 || return "$E_CONNECT"
	silo_bucket_configure "$name" "$quota" "$versioning"
}

silo_bucket_configure() {
	local name="$1" quota="$2" versioning="$3" state
	silo_mcli quota set "arc/$name" --size "${quota}MiB" > /dev/null 2>&1 || return "$E_CONNECT"
	state=$(silo_mcli version info "arc/$name" --json 2> /dev/null | jq -r '.versioning.status // ""')
	if [ "$versioning" = 'yes' ] && [ "$state" != 'Enabled' ]; then
		silo_mcli version enable "arc/$name" > /dev/null 2>&1 || return "$E_CONNECT"
	elif [ "$versioning" != 'yes' ] && [ "$state" = 'Enabled' ]; then
		silo_mcli version suspend "arc/$name" > /dev/null 2>&1 || return "$E_CONNECT"
	fi
	return 0
}

# Usage of a bucket from Silo's scanner: "BYTES OBJECTS"
silo_bucket_usage() {
	silo_mcli stat "arc/$1" --json 2> /dev/null | jq -r '"\(.Usage.size // 0) \(.Usage.objectsCount // 0)"'
}

silo_bucket_remove() {
	if silo_bucket_exists "$1"; then
		silo_mcli rb --force --dangerous "arc/$1" > /dev/null 2>&1 || return "$E_CONNECT"
	fi
	return 0
}

#----------------------------------------------------------#
#                         S3 keys                          #
#----------------------------------------------------------#

# Create a service account of USER; prints the JSON of mcli (accessKey,
# secretKey) on stdout
silo_key_create() {
	local user="$1" name="$2" bucket="$3" access="$4" expires="$5" tmp args=()
	args=(admin user svcacct add arc "$(silo_iam_user "$user")" --name "$name" --description "ArcPanel S3 key of $user" --json)
	tmp=''
	if [ -n "$bucket" ] || [ "$access" = 'ro' ]; then
		tmp=$(mktemp)
		silo_key_policy_json "$user" "$bucket" "$access" > "$tmp"
		args+=(--policy "$tmp")
	fi
	if [ -n "$expires" ]; then
		args+=(--expiry "$expires")
	fi
	silo_mcli "${args[@]}" 2> /dev/null
	local rc=$?
	[ -n "$tmp" ] && rm -f "$tmp"
	return "$rc"
}

silo_key_remove() {
	silo_mcli admin user svcacct remove arc "$1" > /dev/null 2>&1
}

# Access key IDs of the service accounts of USER in Silo, one per line; 1
# when Silo does not list them, which is not the same as no keys
silo_key_ids() {
	local out
	out=$(silo_mcli admin user svcacct list arc "$(silo_iam_user "$1")" --json 2> /dev/null) || return 1
	jq -r 'select(.accessKey) | .accessKey' <<< "$out"
}

#----------------------------------------------------------#
#                         Output                           #
#----------------------------------------------------------#

# JSON object of a flat file, keyed by its first field; numbers for the
# numeric keys of NUMERIC (space separated)
silo_file_json() {
	local file="$1" list="$2" numeric="$3"
	local -n keys="$list"
	[ -f "$file" ] || {
		echo '{}'
		return 0
	}
	awk -v q="'" -v keys="${keys[*]}" -v numeric=" $numeric " '
		function esc(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); return s }
		BEGIN { n = split(keys, k, " "); printf "{"; first = 1 }
		{
			delete v
			line = $0
			while (match(line, /[A-Z_]+=\047[^\047]*\047/)) {
				pair = substr(line, RSTART, RLENGTH)
				eq = index(pair, "=")
				v[substr(pair, 1, eq - 1)] = substr(pair, eq + 2, length(pair) - eq - 2)
				line = substr(line, RSTART + RLENGTH)
			}
			if (!(k[1] in v)) next
			printf "%s\n  \"%s\": {", (first ? "" : ","), esc(v[k[1]])
			first = 0
			for (i = 1; i <= n; i++) {
				val = v[k[i]]
				if (index(numeric, " " k[i] " ") && val ~ /^[0-9]+$/) {
					printf "%s\"%s\": %s", (i > 1 ? ", " : ""), k[i], val
				} else {
					printf "%s\"%s\": \"%s\"", (i > 1 ? ", " : ""), k[i], esc(val)
				}
			}
			printf "}"
		}
		END { printf "%s}\n", (first ? "" : "\n") }' "$file"
}

#----------------------------------------------------------#
#                     Rebuild and repair                   #
#----------------------------------------------------------#

# Compare USER's buckets and keys with Silo; print one "USER|KIND|DETAIL"
# line per difference, and fix it when REPAIR is yes (spec §8). Usage of
# each bucket is recorded either way.
silo_rebuild_user() {
	local user="$1" repair="$2" line bucket quota versioning usage key ids
	local file
	file=$(silo_buckets_file "$user")
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		bucket=$(silo_field "$line" BUCKET)
		quota=$(silo_field "$line" QUOTA)
		versioning=$(silo_field "$line" VERSIONING)
		if ! silo_bucket_exists "$bucket"; then
			echo "$user|bucket|$bucket is missing in object storage"
			if [ "$repair" = 'yes' ]; then
				silo_bucket_create "$bucket" "$quota" "$versioning"
			fi
			continue
		fi
		if [ "$repair" = 'yes' ]; then
			silo_bucket_configure "$bucket" "$quota" "$versioning"
		fi
		usage=$(silo_bucket_usage "$bucket")
		if [ -n "$usage" ]; then
			silo_set_fields "$file" SILO_BUCKET_KEYS "$bucket" "USED=${usage% *}" "OBJECTS=${usage#* }"
		fi
	done < <(cat "$file" 2> /dev/null)

	local recreated=''
	if [ -n "$(silo_buckets "$user")" ] || [ -n "$(silo_keys "$user")" ]; then
		if ! silo_iam_exists "$user"; then
			echo "$user|user|$(silo_iam_user "$user") is missing in object storage"
			recreated='yes'
		fi
		if [ "$repair" = 'yes' ]; then
			silo_sync_user "$user" || recreated=''
		fi
	fi

	# Keys: a line without its service account cannot come back (the secret
	# is gone); a service account without a line is not the panel's. Both
	# are permanent, so a listing that fails changes nothing, and a
	# difference is fixed only when SILO_MISS_LIMIT repairs in a row saw it,
	# or at once for the lines of an IAM user that had to be created again
	if ! ids=$(silo_key_ids "$user"); then
		echo "$user|key|the S3 keys could not be listed"
		return 0
	fi
	SILO_MISSES_NEW=''
	for key in $(silo_keys "$user"); do
		if ! grep -qx -- "$key" <<< "$ids"; then
			echo "$user|key|S3 key $key is missing in object storage"
			if [ "$repair" = 'yes' ]; then
				silo_miss "$user" record "$key"
				if [ "$recreated" = 'yes' ] || [ "$SILO_MISS_COUNT" -ge "$SILO_MISS_LIMIT" ]; then
					silo_delete_line "$(silo_keys_file "$user")" KEY "$key"
				fi
			fi
		fi
	done
	for key in $ids; do
		if [ -z "$(silo_key_line "$user" "$key")" ]; then
			echo "$user|key|service account $key has no S3 key record"
			if [ "$repair" = 'yes' ]; then
				silo_miss "$user" account "$key"
				if [ "$SILO_MISS_COUNT" -ge "$SILO_MISS_LIMIT" ]; then
					silo_key_remove "$key"
				fi
			fi
		fi
	done
	if [ "$repair" = 'yes' ]; then
		silo_misses_save "$user"
	fi
	return 0
}

# Count one more repair that saw KIND ID of USER differ (record: a key line
# without its service account, account: the reverse) into SILO_MISS_COUNT;
# the count is kept in SILO_MISSES_NEW until silo_misses_save. A difference
# fixed now is not carried on
silo_miss() {
	local n
	n=$(awk -F'|' -v u="$1" -v k="$2" -v i="$3" '$1 == u && $2 == k && $3 == i { print $4 }' "$SILO_MISSES" 2> /dev/null)
	SILO_MISS_COUNT=$((${n:-0} + 1))
	if [ "$SILO_MISS_COUNT" -lt "$SILO_MISS_LIMIT" ]; then
		SILO_MISSES_NEW+="$1|$2|$3|$SILO_MISS_COUNT"$'\n'
	fi
}

# Replace the counts of USER by those of this repair, so a difference that
# went away starts again from zero
silo_misses_save() {
	local tmp
	tmp=$(mktemp)
	awk -F'|' -v u="$1" '$1 != u' "$SILO_MISSES" 2> /dev/null > "$tmp"
	printf '%s' "$SILO_MISSES_NEW" >> "$tmp"
	install -m 600 "$tmp" "$SILO_MISSES"
	rm -f "$tmp"
}

#----------------------------------------------------------#
#                         Backups                          #
#----------------------------------------------------------#

# Folder that v-backup-user-restic mirrors the objects of USER into for the
# snapshot: on the backup disk, not below $ARCPANEL, which a large bucket
# could fill (spec §8)
silo_backup_dir() {
	echo "${BACKUP_TEMP:-$BACKUP}/arcpanel-objects-$1"
}

# The objects folder of USER's restic SNAPSHOT, read from the paths the
# snapshot was made of, since BACKUP_TEMP may have changed since; empty
# when the snapshot holds no objects
silo_snapshot_objects_dir() {
	local dir
	dir=$(restic_user "$1" cat snapshot -- "$2" 2> /dev/null \
		| jq -r --arg name "arcpanel-objects-$1" '.paths[]? | select(endswith("/" + $name))' 2> /dev/null | head -n 1)
	if [[ "$dir" == /* ]] && [[ "$dir" != *..* ]]; then
		echo "$dir"
	fi
}

# Restore USER's buckets of a restic SNAPSHOT from the backed-up
# buckets.conf FILE, with the objects the snapshot holds, through the
# working folder WORK
silo_restore_snapshot() {
	local user="$1" snapshot="$2" file="$3" work="$4" dir objects=''
	dir=$(silo_snapshot_objects_dir "$user" "$snapshot")
	if [ -n "$dir" ] && restic_restore_data "$work" "$snapshot" --include="$dir" > /dev/null 2>&1; then
		find "$work$dir" \( -type l -o \( ! -type f ! -type d \) \) -delete 2> /dev/null
		objects="$work$dir"
	fi
	silo_restore_user "$user" "$file" "$objects"
}

# Mirror the current objects of USER's BUCKET into DIR; 1 on failure
silo_backup_bucket() {
	mkdir -p "$2"
	chmod 700 "$2"
	silo_mcli mirror --quiet --overwrite --remove "arc/$1" "$2" > /dev/null 2>&1
}

# Restore USER's buckets from the backed-up buckets.conf FILE, with the
# objects of OBJECTS/<bucket>/ when that folder exists; one log line per
# bucket. Buckets whose name another account holds are skipped, and so are
# new buckets beyond the limits of the package: the file comes from the
# home, where the account can change it. A bucket the account still has
# keeps its quota and versioning. S3 keys are never restored: their
# secrets are not kept.
silo_restore_user() {
	local user="$1" file="$2" objects="$3" line bucket quota versioning owner count=0
	[ -s "$file" ] || return 0
	count=$(grep -c "^BUCKET=" "$file")
	if ! silo_installed; then
		echo "[ ! ] Object storage is not installed on this server: $count buckets skipped"
		return 0
	fi
	if ! silo_allowed "$user"; then
		echo "[ ! ] The package of $user has no object storage: $count buckets skipped"
		return 0
	fi
	if ! silo_wait; then
		echo "[ ! ] The object storage service does not answer: $count buckets skipped"
		return 0
	fi
	silo_lock
	while IFS= read -r line; do
		bucket=$(silo_field "$line" BUCKET)
		quota=$(silo_field "$line" QUOTA)
		versioning=$(silo_field "$line" VERSIONING)
		[ -n "$bucket" ] || continue
		if ! silo_bucket_name_valid "$bucket" || ! [[ "$quota" =~ ^[0-9]{1,9}$ ]] || { [ "$versioning" != 'yes' ] && [ "$versioning" != 'no' ]; }; then
			echo "[ ! ] Bucket $bucket skipped: invalid record"
			continue
		fi
		owner=$(silo_bucket_owner "$bucket")
		if { [ -n "$owner" ] && [ "$owner" != "$user" ]; } || { [ -z "$owner" ] && silo_bucket_exists "$bucket"; }; then
			echo "[ ! ] Bucket $bucket skipped: the name is taken on this server"
			continue
		fi
		if [ -z "$owner" ] && ! silo_limits_allow "$user" "$quota" '' yes; then
			echo "[ ! ] Bucket $bucket skipped: $REPLY"
			continue
		fi
		if [ -n "$owner" ]; then
			line=$(silo_bucket_line "$user" "$bucket")
			quota=$(silo_field "$line" QUOTA)
			versioning=$(silo_field "$line" VERSIONING)
		fi
		if ! silo_bucket_create "$bucket" "$quota" "$versioning"; then
			echo "[ ! ] Bucket $bucket could not be created"
			continue
		fi
		if [ -z "$owner" ]; then
			local now
			now=$(date +'%T %F')
			silo_write_line "$(silo_buckets_file "$user")" SILO_BUCKET_KEYS "BUCKET=$bucket" "QUOTA=$quota" \
				"VERSIONING=$versioning" "USED=0" "OBJECTS=0" "SUSPENDED=no" "TIME=${now% *}" "DATE=${now#* }"
		fi
		if [ -n "$objects" ] && [ -d "$objects/$bucket" ]; then
			if silo_mcli mirror --quiet --overwrite "$objects/$bucket" "arc/$bucket" > /dev/null 2>&1; then
				echo "$(date "+%F %T") bucket $bucket with its objects"
			else
				echo "[ ! ] The objects of bucket $bucket were not restored completely"
			fi
		else
			echo "$(date "+%F %T") bucket $bucket"
		fi
	done < "$file"
	silo_sync_user "$user"
}

#----------------------------------------------------------#
#                     Deleted accounts                     #
#----------------------------------------------------------#

# Remove the BUCKETS and KEYS (space separated) of the deleted account USER
# from Silo, and its IAM user and policy unless the account exists again
# with object storage; a bucket another account owns by now stays. 1 when
# a part failed
silo_purge() {
	local user="$1" buckets="$2" keys="$3" rc=0 item
	for item in $buckets; do
		[ -n "$(silo_bucket_owner "$item")" ] && continue
		silo_bucket_remove "$item" || rc=1
	done
	for item in $keys; do
		if ! silo_key_remove "$item" && silo_mcli admin user svcacct info arc "$item" > /dev/null 2>&1; then
			rc=1
		fi
	done
	if [ ! -f "$(silo_buckets_file "$user")" ] && [ ! -f "$(silo_keys_file "$user")" ]; then
		if silo_iam_exists "$user"; then
			silo_mcli admin user remove arc "$(silo_iam_user "$user")" > /dev/null 2>&1 || rc=1
		fi
		silo_mcli admin policy remove arc "$(silo_policy_name "$user")" > /dev/null 2>&1
	fi
	return "$rc"
}

# Keep what silo_purge could not remove for the next repair
silo_orphan_add() {
	local now
	now=$(date +'%T %F')
	mkdir -p "$SILO_DIR"
	echo "USER='$1' BUCKETS='$2' KEYS='$3' TIME='${now% *}' DATE='${now#* }'" >> "$SILO_ORPHANS"
	chmod 600 "$SILO_ORPHANS"
}

# Report the left-overs of deleted accounts, one "USER|orphan|DETAIL" line
# each, and remove them from Silo when REPAIR is yes; a line stays until
# its removal succeeds
silo_orphans_check() {
	local repair="$1" line user buckets keys tmp
	[ -s "$SILO_ORPHANS" ] || return 0
	tmp=$(mktemp)
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		user=$(silo_field "$line" USER)
		buckets=$(silo_field "$line" BUCKETS)
		keys=$(silo_field "$line" KEYS)
		echo "$user|orphan|buckets ($buckets) and S3 keys of the deleted account are left in object storage"
		if [ "$repair" = 'yes' ] && silo_purge "$user" "$buckets" "$keys"; then
			continue
		fi
		echo "$line" >> "$tmp"
	done < "$SILO_ORPHANS"
	if [ "$repair" = 'yes' ]; then
		install -m 600 "$tmp" "$SILO_ORPHANS"
	fi
	rm -f "$tmp"
}
