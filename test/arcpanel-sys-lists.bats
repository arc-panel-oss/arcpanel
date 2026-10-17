#!/usr/bin/env bats
# Cross-user lists of REST API v2 (E43 M3, B2):
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §5.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
SL_RES=arcbatsslr
SL_CUST=arcbatsslc
SL_OUT=arcbatsslo
SL_CUST_DOMAIN=slc.arcbats.example
SL_OUT_DOMAIN=slo.arcbats.example
PASS="Sl-$(date +%s)-Pass"

# A package with every quota key finite
make_package() {
	local name=$1 amount=$2 tmp
	tmp=$(mktemp)
	sed -e "s/^\(WEB_DOMAINS\|DNS_DOMAINS\|MAIL_DOMAINS\|MAIL_ACCOUNTS\|DATABASES\|CRON_JOBS\)=.*/\1='$amount'/" \
		-e "s/^DISK_QUOTA=.*/DISK_QUOTA='$((amount * 1000))'/" \
		-e "s/^BANDWIDTH=.*/BANDWIDTH='$((amount * 1000))'/" \
		"$PKG_DIR/default.pkg" > "$tmp"
	v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
	rm -f "$tmp"
}

cleanup() {
	local user
	for user in "$SL_CUST" "$SL_OUT" "$SL_RES"; do
		# The node app and backup records of the cases are synthetic
		rm -f "$ARCPANEL/data/users/$user/nodeapps.conf"
		if [ -f "$ARCPANEL/data/users/$user/backup.conf" ]; then
			: > "$ARCPANEL/data/users/$user/backup.conf"
		fi
		v-delete-user "$user" > /dev/null 2>&1 || true
	done
	for package in slbats-plan slbats-small; do
		v-delete-user-package "$package" > /dev/null 2>&1 || true
		rm -f "$PKG_DIR/$package.tenancy"
	done
}

setup_file() {
	cleanup
	make_package slbats-plan 20
	make_package slbats-small 2
	v-change-package-tenancy slbats-plan USERS 5
	v-add-user "$SL_RES" "$PASS" "$SL_RES@example.invalid" slbats-plan > /dev/null
	v-change-user-role "$SL_RES" reseller
	v-add-user "$SL_CUST" "$PASS" "$SL_CUST@example.invalid" slbats-small > /dev/null
	v-change-user-owner "$SL_CUST" "$SL_RES"
	v-add-user "$SL_OUT" "$PASS" "$SL_OUT@example.invalid" slbats-small > /dev/null

	v-add-web-domain "$SL_CUST" "$SL_CUST_DOMAIN" > /dev/null
	v-add-web-domain "$SL_OUT" "$SL_OUT_DOMAIN" > /dev/null
	v-add-cron-job "$SL_CUST" '*/5' '*' '*' '*' '*' "echo 'sl bats' > /dev/null" > /dev/null
	v-add-database "$SL_OUT" db db "Sl-$(date +%s)-Db1" mysql > /dev/null
	echo "DOMAIN='$SL_CUST_DOMAIN' NAME='slc-arcbats' START='npm:start' CWD='public_html' NODE_VERSION='22' PORT='3901' INSTANCES='1' MAX_MEMORY='512' TEMPLATE='nodejs-proxy' PREV_TPL='default' STATE='running' SUSPENDED='no' TIME='10:00:00' DATE='2026-10-17'" \
		> "$ARCPANEL/data/users/$SL_CUST/nodeapps.conf"
	echo "BACKUP='$SL_OUT.2026-10-17_10-00-00.tar' TYPE='local' SIZE='12' WEB='$SL_OUT_DOMAIN' DNS='' MAIL='' DB='${SL_OUT}_db' CRON='' UDIR='' RUNTIME='1' TIME='10:00:00' DATE='2026-10-17'" \
		> "$ARCPANEL/data/users/$SL_OUT/backup.conf"
}

teardown_file() {
	cleanup
}

# The objects of USER in the json answer of COMMAND, keys sorted
sys_objects() {
	"$1" '' '' json | jq -S --arg user "$2" '.OBJECTS[$user]'
}

@test "v-list-sys-web-domains, -databases, -cron-jobs and -backups print each user's objects as the per-user commands do" {
	assert_equal "$(sys_objects v-list-sys-web-domains "$SL_CUST")" "$(v-list-web-domains "$SL_CUST" json | jq -S .)"
	assert_equal "$(sys_objects v-list-sys-web-domains "$SL_OUT")" "$(v-list-web-domains "$SL_OUT" json | jq -S .)"
	assert_equal "$(sys_objects v-list-sys-databases "$SL_OUT")" "$(v-list-databases "$SL_OUT" json | jq -S .)"
	assert_equal "$(sys_objects v-list-sys-cron-jobs "$SL_CUST")" "$(v-list-cron-jobs "$SL_CUST" json | jq -S .)"
	assert_equal "$(sys_objects v-list-sys-cron-jobs "$SL_CUST" | jq -r '.[].CMD')" "echo 'sl bats' > /dev/null"
	assert_equal "$(sys_objects v-list-sys-backups "$SL_OUT")" "$(v-list-user-backups "$SL_OUT" json | jq -S .)"
	assert_equal "$(sys_objects v-list-sys-databases "$SL_CUST")" "{}"
}

@test "v-list-sys-node-apps prints the app records without asking pm2" {
	run v-list-sys-node-apps '' '' json
	assert_success
	assert_equal "$(echo "$output" | jq -c --arg user "$SL_CUST" --arg domain "$SL_CUST_DOMAIN" '.OBJECTS[$user][$domain] | [.DOMAIN, .NAME, .PORT, .STATE, .SUSPENDED]')" \
		"[\"$SL_CUST_DOMAIN\",\"slc-arcbats\",\"3901\",\"running\",\"no\"]"
	assert_equal "$(echo "$output" | jq -r --arg user "$SL_CUST" --arg domain "$SL_CUST_DOMAIN" '.OBJECTS[$user][$domain] | has("STATUS") or has("PID")')" "false"
	assert_equal "$(echo "$output" | jq -c --arg user "$SL_OUT" '.OBJECTS[$user]')" "{}"
}

@test "Without an owner every user is listed; with a reseller, the reseller and its customers" {
	run v-list-sys-web-domains '' '' json
	assert_success
	assert_equal "$(echo "$output" | jq -c '.USERS == (.USERS | sort) and .USERS == .CHANGED_USERS')" "true"
	assert_equal "$(echo "$output" | jq -c --arg a "$SL_CUST" --arg b "$SL_OUT" --arg c "$SL_RES" '[.USERS[] | select(. == $a or . == $b or . == $c)]')" \
		"[\"$SL_CUST\",\"$SL_OUT\",\"$SL_RES\"]"
	assert_equal "$(echo "$output" | jq -r '.USERS | length')" "$(ls -d "$ARCPANEL"/data/users/*/user.conf | wc -l)"
	run v-list-sys-web-domains "$SL_RES" '' json
	assert_success
	assert_equal "$(echo "$output" | jq -c '[.USERS, .CHANGED_USERS, (.OBJECTS | keys)]')" \
		"[[\"$SL_CUST\",\"$SL_RES\"],[\"$SL_CUST\",\"$SL_RES\"],[\"$SL_CUST\",\"$SL_RES\"]]"
	assert_equal "$(echo "$output" | jq -r --arg user "$SL_CUST" '.OBJECTS[$user] | keys[0]')" "$SL_CUST_DOMAIN"
	run v-list-sys-web-domains "$SL_OUT" '' json
	assert_equal "$(echo "$output" | jq -c '.USERS')" "[\"$SL_OUT\"]"
}

@test "UPDATED_SINCE returns only the users whose file changed at or after it, and AS_OF is the start of the listing" {
	local old since before after
	old=$(($(date +%s) - 1000))
	since=$(($(date +%s) - 500))
	touch -d "@$old" "$ARCPANEL/data/users/$SL_CUST/web.conf" "$ARCPANEL/data/users/$SL_RES/web.conf"
	run v-list-sys-web-domains "$SL_RES" "$since" json
	assert_success
	assert_equal "$(echo "$output" | jq -c '[.USERS, .CHANGED_USERS, .OBJECTS]')" "[[\"$SL_CUST\",\"$SL_RES\"],[],{}]"
	touch "$ARCPANEL/data/users/$SL_CUST/web.conf"
	before=$(date +%s)
	run v-list-sys-web-domains "$SL_RES" "$since" json
	after=$(date +%s)
	assert_equal "$(echo "$output" | jq -c '[.USERS, .CHANGED_USERS, (.OBJECTS | keys)]')" "[[\"$SL_CUST\",\"$SL_RES\"],[\"$SL_CUST\"],[\"$SL_CUST\"]]"
	[ "$(echo "$output" | jq -r .AS_OF)" -ge "$before" ] && [ "$(echo "$output" | jq -r .AS_OF)" -le "$after" ] \
		|| fail "AS_OF $(echo "$output" | jq -r .AS_OF) is not between $before and $after"
}

@test "A removed file is a change of its user, through the user's directory" {
	local since dir="$ARCPANEL/data/users/$SL_CUST"
	since=$(($(date +%s) - 500))
	cp -p "$dir/nodeapps.conf" "$BATS_TEST_TMPDIR/nodeapps.conf"
	touch -d "@$(($(date +%s) - 1000))" "$dir/nodeapps.conf" "$dir" "$ARCPANEL/data/users/$SL_RES"
	run v-list-sys-node-apps "$SL_RES" "$since" json
	assert_equal "$(echo "$output" | jq -c '.CHANGED_USERS')" "[]"
	rm -f "$dir/nodeapps.conf"
	run v-list-sys-node-apps "$SL_RES" "$since" json
	assert_equal "$(echo "$output" | jq -c --arg user "$SL_CUST" '[.CHANGED_USERS, .OBJECTS[$user]]')" "[[\"$SL_CUST\"],{}]"
	cp -p "$BATS_TEST_TMPDIR/nodeapps.conf" "$dir/nodeapps.conf"
}

@test "The plain, csv and shell formats print one line per object" {
	run v-list-sys-web-domains "$SL_RES" '' plain
	assert_success
	assert_output "$SL_CUST"$'\t'"$SL_CUST_DOMAIN"$'\t'"$(v-list-web-domains "$SL_CUST" json | jq -r '.[].DATE + "\t" + .[].TIME')"
	run v-list-sys-cron-jobs "$SL_RES" '' csv
	assert_line --index 0 'USER,NAME,DATE,TIME'
	assert_line --index 1 --partial "\"$SL_CUST\",\"1\","
	run v-list-sys-databases "$SL_OUT" '' shell
	assert_line --index 0 --regexp '^USER +NAME +DATE +TIME$'
	assert_line --index 2 --regexp "^$SL_OUT +${SL_OUT}_db +"
}

@test "The commands refuse an unknown owner, a bad time and a bad format" {
	run v-list-sys-web-domains arcbats-nobody '' json
	assert_failure 3
	run v-list-sys-databases '' 'yesterday' json
	assert_failure 2
	run v-list-sys-backups '' '' xml
	assert_failure 2
}

@test "The cross-user commands are in their area sets, v2-read, v2-observe and v2-write" {
	local set cmd area
	for pair in web:v-list-sys-web-domains db:v-list-sys-databases cron:v-list-sys-cron-jobs apps:v-list-sys-node-apps backup:v-list-sys-backups; do
		area=${pair%%:*}
		cmd=${pair#*:}
		for set in "v2-$area" v2-read v2-observe v2-write; do
			grep -q "^COMMANDS='\(.*,\)\?$cmd\(,.*\)\?'" "$ARCPANEL/data/api/$set" || fail "$cmd is not in $set"
		done
	done
}
