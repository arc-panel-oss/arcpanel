#!/bin/bash
# Performance evidence of the cross-user lists of REST API v2 (E43 M3, B2):
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §11.
#
# Adds PERF_USERS synthetic users (default 500) with PERF_DOMAINS web domains
# (default 1500) as records only: a directory in data/users with user.conf and
# web.conf, no system account, no web server configuration. One real user with
# one real domain gives the template line of web.conf. Then indexes the five
# kinds as a client would (limit=1000, every page), changes the web domains of
# PERF_CHANGED users (default 5) and indexes again with updated_since. Prints
# the time and the request count of both passes, then removes everything.
#
# Turns the API on for the run (API_SYSTEM=2, API_ALLOWED_IP=allow-all) and
# restores both at the end. Run as root on a test server, never on production.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
API="$PANEL/api/v2"
CONF="$ARCPANEL/conf/arcpanel.conf"
USERS_DIR="$ARCPANEL/data/users"
PERF_USERS="${PERF_USERS:-500}"
PERF_DOMAINS="${PERF_DOMAINS:-1500}"
PERF_CHANGED="${PERF_CHANGED:-5}"
PREFIX=arcperf
TEMPLATE_USER="${PREFIX}t$RANDOM"
TEMPLATE_DOMAIN="$TEMPLATE_USER.example.test"
PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
ROOT_USER=$(grep "^ROOT_USER=" "$CONF" | cut -d"'" -f2)
OLD_API_SYSTEM=$(grep "^API_SYSTEM=" "$CONF" | cut -d"'" -f2 || true)
OLD_API_ALLOWED_IP=$(grep "^API_ALLOWED_IP=" "$CONF" | cut -d"'" -f2 || true)
BODY="$(mktemp)"
SYNTHETIC=()
KEY_ID=''

clear_panel_cache() {
	runuser -u arcpanelweb -- "$ARCPANEL/php/bin/php" "$ARCPANEL/web-app/artisan" cache:clear > /dev/null
}

cleanup() {
	local user
	# Only the directories this run created
	for user in "${SYNTHETIC[@]}"; do
		[ -n "$user" ] && [ ! -e "/home/$user" ] && rm -rf "${USERS_DIR:?}/$user"
	done
	"$ARCPANEL/bin/v-delete-user" "$TEMPLATE_USER" > /dev/null 2>&1 || true
	if [ -n "$KEY_ID" ]; then
		"$ARCPANEL/bin/v-delete-access-key" "$KEY_ID" > /dev/null 2>&1 || true
	fi
	"$ARCPANEL/bin/v-change-sys-config-value" API_SYSTEM "${OLD_API_SYSTEM:-0}"
	"$ARCPANEL/bin/v-change-sys-config-value" API_ALLOWED_IP "$OLD_API_ALLOWED_IP"
	clear_panel_cache
	rm -f "$BODY"
}
trap cleanup EXIT

# call KEY PATH -> prints the status; the answer is in $BODY
call() {
	curl -sk -o "$BODY" -w '%{http_code}' -H "Authorization: Bearer $1" -H 'Accept: application/json' "$API$2"
}

now_ms() { date +%s%3N; }

REQUESTS=0
# index KEY KIND [UPDATED_SINCE] -> pages through one kind; sets AS_OF, ITEMS and CHANGED
index() {
	local key=$1 kind=$2 since=${3:-} offset=0 total status query
	ITEMS=0
	while :; do
		query="limit=1000&offset=$offset"
		[ -n "$since" ] && query="$query&updated_since=$since"
		status=$(call "$key" "/$kind?$query")
		REQUESTS=$((REQUESTS + 1))
		[ "$status" = 200 ] || {
			echo "GET /$kind?$query answered $status" >&2
			exit 1
		}
		if [ "$offset" = 0 ]; then
			AS_OF=$(jq -r .meta.as_of "$BODY")
			CHANGED=$(jq -r '.meta.changed_users | length' "$BODY")
		fi
		total=$(jq -r .meta.total "$BODY")
		ITEMS=$((ITEMS + $(jq -r '.data | length' "$BODY")))
		offset=$((offset + 1000))
		[ "$offset" -lt "$total" ] || break
	done
}

KINDS=(web-domains databases cron-jobs node-apps backups)
declare -A SINCE

# pass KEY LABEL -> indexes the five kinds, with SINCE[kind] when set
pass() {
	local key=$1 label=$2 kind start elapsed
	REQUESTS=0
	start=$(now_ms)
	for kind in "${KINDS[@]}"; do
		index "$key" "$kind" "${SINCE[$kind]:-}"
		echo "  $label $kind: $ITEMS items, $CHANGED changed users"
		SINCE[$kind]=$AS_OF
	done
	elapsed=$(($(now_ms) - start))
	echo "$label index: $REQUESTS requests in $((elapsed / 1000)).$(printf '%03d' $((elapsed % 1000))) s"
}

"$ARCPANEL/bin/v-change-sys-config-value" API_SYSTEM 2
"$ARCPANEL/bin/v-change-sys-config-value" API_ALLOWED_IP allow-all
clear_panel_cache

# The template: one real user with one real domain
"$ARCPANEL/bin/v-add-user" "$TEMPLATE_USER" "$PASS" "$TEMPLATE_USER@example.invalid" > /dev/null
"$ARCPANEL/bin/v-add-web-domain" "$TEMPLATE_USER" "$TEMPLATE_DOMAIN" > /dev/null
WEB_LINE=$(grep "^DOMAIN='$TEMPLATE_DOMAIN'" "$USERS_DIR/$TEMPLATE_USER/web.conf")

echo "Adding $PERF_USERS synthetic users with $PERF_DOMAINS web domains"
start=$(now_ms)
for i in $(seq 1 "$PERF_USERS"); do
	user=$(printf '%s%04d' "$PREFIX" "$i")
	if [ -e "$USERS_DIR/$user" ]; then
		echo "$USERS_DIR/$user exists already" >&2
		exit 1
	fi
	SYNTHETIC+=("$user")
	mkdir "$USERS_DIR/$user"
	sed -e "s/^NAME=.*/NAME='Perf $i'/" -e "s/^CONTACT=.*/CONTACT='$user@example.invalid'/" \
		-e "s/^ROLE=.*/ROLE='user'/" "$USERS_DIR/$TEMPLATE_USER/user.conf" > "$USERS_DIR/$user/user.conf"
	: > "$USERS_DIR/$user/web.conf"
done
for i in $(seq 1 "$PERF_DOMAINS"); do
	user=${SYNTHETIC[$(((i - 1) % PERF_USERS))]}
	echo "${WEB_LINE//$TEMPLATE_DOMAIN/d$i.$user.example.test}" >> "$USERS_DIR/$user/web.conf"
done
echo "Added in $((($(now_ms) - start) / 1000)) s; $(ls -d "$USERS_DIR"/*/user.conf | wc -l) users on the server"

KEY=$("$ARCPANEL/bin/v-add-access-key" "$ROOT_USER" v2-observe arcperf json | jq -r '"\(.ACCESS_KEY_ID):\(.SECRET_ACCESS_KEY)"')
KEY_ID=${KEY%%:*}

pass "$KEY" Full
sleep 1
for i in $(seq 1 "$PERF_CHANGED"); do
	user=${SYNTHETIC[$((i - 1))]}
	echo "${WEB_LINE//$TEMPLATE_DOMAIN/new$i.$user.example.test}" >> "$USERS_DIR/$user/web.conf"
done
pass "$KEY" Incremental
