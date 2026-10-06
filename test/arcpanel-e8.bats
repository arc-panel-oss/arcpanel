#!/usr/bin/env bats
# ArcPanel 2.0 cut-over (E8,
# docs/features/specs/2026-10-06-004-e8-legacy-retirement-design.md).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
	. /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

PANEL="https://127.0.0.1:8083"
E8_USER="arcbatse8"
E8_JAR="$BATS_FILE_TMPDIR/e8.jar"

e8_login() {
	local token
	rm -f "$E8_JAR"
	token="$(curl -sk -c "$E8_JAR" -b "$E8_JAR" "$PANEL/login" | grep -o 'name="_token" value="[^"]*"' | head -n1 | sed 's/.*value="\([^"]*\)"/\1/')"
	curl -sk -c "$E8_JAR" -b "$E8_JAR" -o /dev/null --data-urlencode "_token=$token" \
		--data-urlencode "user=$E8_USER" --data-urlencode "password=$E8_PASS" "$PANEL/login"
}

# status and redirect target for the signed-in test user
e8_get() {
	curl -sk -b "$E8_JAR" -c "$E8_JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$@"
}

# status and redirect target for a guest
guest_get() {
	curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "$@"
}

setup_file() {
	E8_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	export E8_PASS
	v-add-user "$E8_USER" "$E8_PASS" "$E8_USER@example.invalid" default > /dev/null
	v-add-web-domain "$E8_USER" "$E8_USER.example" > /dev/null
}

teardown_file() {
	v-delete-user "$E8_USER" > /dev/null 2>&1 || true
}

# M1: root URL (spec §5)

@test "The panel answers at the root URL" {
	run guest_get "$PANEL/"
	assert_output "302 $PANEL/login"
	run guest_get "$PANEL/login"
	assert_output "200 "
}

@test "The /app prefix of 1.x answers 301 with the query string" {
	run guest_get "$PANEL/app/login"
	assert_output "301 $PANEL/login"
	run guest_get "$PANEL/app/web/domains?owner=bob"
	assert_output "301 $PANEL/web/domains?owner=bob"
	run guest_get "$PANEL/app"
	assert_output "301 $PANEL/"
	# never a redirect to another host
	run guest_get "$PANEL/app//example.net/x"
	assert_output "301 $PANEL/example.net/x"
}

@test "Legacy page paths answer 301 or 302 to the panel pages" {
	e8_login
	run e8_get "$PANEL/list/user/"
	assert_output "301 $PANEL/users"
	run e8_get "$PANEL/list/package/"
	assert_output "301 $PANEL/packages"
	run e8_get "$PANEL/fm/"
	assert_output "301 $PANEL/files"
	run e8_get "$PANEL/list/web/index.php"
	assert_output "301 $PANEL/web/domains"
	run e8_get "$PANEL/edit/user/"
	assert_output "302 $PANEL/account"
	run e8_get "$PANEL/download/web-log/?domain=$E8_USER.example&type=error"
	assert_output "302 $PANEL/web/domains/$E8_USER/$E8_USER.example/logs/error/download"
}

@test "Guests who open an object page go to the login page" {
	run guest_get "$PANEL/edit/web/?domain=$E8_USER.example"
	assert_output "302 $PANEL/login"
}

@test "Unknown legacy paths and form posts answer 404" {
	e8_login
	run e8_get "$PANEL/list/nothing/"
	assert_output "404 "
	run e8_get -X POST "$PANEL/list/web/"
	assert_output "404 "
	run guest_get "$PANEL/inc/main.php"
	assert_output "404 "
}

@test "The 1.x logout link goes to the login page" {
	run guest_get "$PANEL/logout/"
	assert_output "301 $PANEL/login"
}

@test "The web log download serves the domain log" {
	e8_login
	run curl -sk -b "$E8_JAR" -D - -o /dev/null "$PANEL/web/domains/$E8_USER/$E8_USER.example/logs/access/download"
	assert_output --partial "HTTP/2 200"
	assert_output --partial "filename=$E8_USER.example.access-log.txt"
}

@test "The ARCPANEL_APP_* switches are gone" {
	run grep -c "^ARCPANEL_APP_" /usr/local/hestia/conf/hestia.conf
	assert_output "0"
	run v-list-sys-config json
	assert_success
	refute_output --partial "ARCPANEL_APP_"
	run v-list-sys-config
	refute_output --partial "Panel app"
}

@test "The panel nginx configuration is valid" {
	run /usr/local/hestia/nginx/sbin/hestia-nginx -t
	assert_success
}

# M2: lib/ and the deletion of web/ (spec §6)

HESTIA_DIR="/usr/local/hestia"

@test "web/ is gone and lib/ holds the libraries" {
	assert_not_exists "$HESTIA_DIR/web"
	assert_file_exists "$HESTIA_DIR/lib/vendor/autoload.php"
	assert_file_exists "$HESTIA_DIR/lib/quickinstall/vendor/autoload.php"
	assert_file_executable "$HESTIA_DIR/lib/mail-wrapper.php"
	run grep -c "web/rrd\|web/inc\|web/css" "$HESTIA_DIR/func/main.sh"
	assert_output "0"
}

@test "2FA: v-add-user-2fa and v-check-user-2fa work from lib/2fa" {
	run v-add-user-2fa "$E8_USER"
	assert_success
	secret="$(v-list-user "$E8_USER" json | jq -r ".\"$E8_USER\".TWOFA")"
	assert [ -n "$secret" ]
	code="$("$HESTIA_DIR/php/bin/php" -r 'require "/usr/local/hestia/lib/vendor/autoload.php"; $t = new RobThree\Auth\TwoFactorAuth(new RobThree\Auth\Providers\Qr\QRServerProvider(), "ArcPanel"); echo $t->getCode($argv[1]);' "$secret")"
	run v-check-user-2fa "$E8_USER" "$code"
	assert_success
	run v-check-user-2fa "$E8_USER" 000000
	assert_failure
	v-delete-user-2fa "$E8_USER"
}

@test "Quick Install lists its installers from lib/quickinstall" {
	run v-list-quick-install-apps json
	assert_success
	assert_output --partial '"NAME": "WordPress"'
}

@test "Languages come from lib/locale" {
	run v-list-sys-languages json
	assert_success
	assert_output --partial '"en"'
	assert_output --partial '"vi"'
}

@test "Themes are the panel themes" {
	run v-list-sys-themes json
	assert_success
	assert_equal "$(echo "$output" | jq -c .)" '["auto","light","nocturne"]'
}

@test "White Label logos come from data/white-label and are served under /images/custom/" {
	cp "$HESTIA_DIR/lib/images/logo-header.svg" "$BATS_FILE_TMPDIR/default-header.svg"
	mkdir -p "$HESTIA_DIR/data/white-label"
	printf '<svg xmlns="http://www.w3.org/2000/svg"><title>e8</title></svg>\n' > "$HESTIA_DIR/data/white-label/logo-header.svg"
	run v-update-white-label-logo
	assert_success
	run cat "$HESTIA_DIR/lib/images/logo-header.svg"
	assert_output --partial '<title>e8</title>'
	run curl -sk "$PANEL/images/custom/logo-header.svg"
	assert_output --partial '<title>e8</title>'
	run curl -sk -o /dev/null -w '%{http_code}' "$PANEL/images/logo.svg"
	assert_output "200"
	rm -f "$HESTIA_DIR/data/white-label/logo-header.svg"
	cp "$BATS_FILE_TMPDIR/default-header.svg" "$HESTIA_DIR/lib/images/logo-header.svg"
}

@test "The API script answers from lib/api" {
	run curl -sk -X POST "$PANEL/api/"
	refute_output --partial "404 Not Found"
	assert_output --partial "Error"
	# every /api/ path runs lib/api/index.php: helpers.php never runs or leaks
	run curl -sk "$PANEL/api/helpers.php"
	refute_output --partial "<?php"
	refute_output --partial "function tohtml"
}

e8_sys_config() {
	v-list-sys-config json | jq -r ".config.$1"
}

# Commands that put back what a case changed; teardown runs them even when
# the case fails half-way.
e8_restore_later() {
	printf '%s\n' "$*" >> "$BATS_TEST_TMPDIR/restore"
}

teardown() {
	if [ -f "$BATS_TEST_TMPDIR/restore" ]; then
		bash "$BATS_TEST_TMPDIR/restore" > /dev/null 2>&1 || true
		rm -f "$BATS_TEST_TMPDIR/restore"
	fi
}

# POST to the API with the access key of the test; prints the response body
e8_api_call() {
	curl -sk -X POST --data-urlencode "access_key=$E8_KEY_ID" --data-urlencode "secret_key=$E8_KEY_SECRET" \
		--data-urlencode "returncode=no" --data-urlencode "cmd=v-list-sys-info" --data-urlencode "arg1=json" \
		"$PANEL/api/index.php"
}

# Waits until the API answer contains the text. PHP-FPM keeps the compiled
# lib/api/index.php until OPcache revalidates it (opcache.revalidate_freq).
e8_api_says() {
	local i
	for i in $(seq 1 10); do
		e8_api_call | grep -qF "$1" && return 0
		sleep 1
	done
	return 1
}

@test "v-change-sys-api enables and disables the API in lib/api" {
	local key
	if grep -q '^die("Error: Disabled");' "$HESTIA_DIR/lib/api/index.php"; then
		e8_restore_later "v-change-sys-api disable"
	else
		e8_restore_later "v-change-sys-api enable api"
	fi
	e8_restore_later "v-change-sys-config-value API '$(e8_sys_config API)'"
	e8_restore_later "v-change-sys-config-value API_SYSTEM '$(e8_sys_config API_SYSTEM)'"
	e8_restore_later "v-change-sys-config-value API_ALLOWED_IP '$(e8_sys_config API_ALLOWED_IP)'"
	# No permission sets: a key of the root user may run every command
	key="$(v-add-access-key "$(e8_sys_config ROOT_USER)" "" e8-bats json)"
	E8_KEY_ID="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	E8_KEY_SECRET="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	e8_restore_later "v-delete-access-key '$E8_KEY_ID'"

	run v-change-sys-api enable api
	assert_success
	v-change-sys-config-value API_ALLOWED_IP allow-all
	assert_equal "$(e8_sys_config API_SYSTEM)" "1"
	run grep -c '^//die("Error: Disabled");' "$HESTIA_DIR/lib/api/index.php"
	assert_output "1"
	run e8_api_says '"sysinfo"'
	assert_success
	run e8_api_call
	assert_output --partial '"ARCPANEL"'

	run v-change-sys-api disable
	assert_success
	assert_equal "$(e8_sys_config API_SYSTEM)" "0"
	run grep -c '^die("Error: Disabled");' "$HESTIA_DIR/lib/api/index.php"
	assert_output "1"
	run e8_api_says "Error: Disabled"
	assert_success
	run e8_api_call
	refute_output --partial '"sysinfo"'
}

@test "The file manager commands only toggle FILE_MANAGER" {
	e8_restore_later "v-change-sys-config-value FILE_MANAGER '$(e8_sys_config FILE_MANAGER)'"
	if [ ! -d "$HESTIA_DIR/web" ]; then
		e8_restore_later "rm -rf '$HESTIA_DIR/web'"
	fi
	# A FileGator leftover of 1.x
	mkdir -p "$HESTIA_DIR/web/fm"
	touch "$HESTIA_DIR/web/fm/index.php"

	run v-delete-sys-filemanager quiet
	assert_success
	assert_equal "$(e8_sys_config FILE_MANAGER)" "false"
	assert_not_exists "$HESTIA_DIR/web/fm"

	run v-add-sys-filemanager quiet
	assert_success
	assert_equal "$(e8_sys_config FILE_MANAGER)" "true"
	assert_not_exists "$HESTIA_DIR/web/fm"
	run grep -rl "filegator" "$HESTIA_DIR/conf" "$HESTIA_DIR/nginx/conf"
	assert_output ""
}

@test "RRD data is written under data/rrd" {
	run v-update-sys-rrd
	assert_success
	assert_file_exists "$HESTIA_DIR/data/rrd/la/la.rrd"
	run v-export-rrd la daily
	assert_success
}

e8_fake_sendmail() {
	mkdir -p "$BATS_FILE_TMPDIR/mail"
	rm -f "$BATS_FILE_TMPDIR/mail/"*
	cat > "$BATS_FILE_TMPDIR/sendmail" <<FAKE
#!/bin/bash
echo "\$*" > "$BATS_FILE_TMPDIR/mail/args"
cat > "$BATS_FILE_TMPDIR/mail/message"
FAKE
	chmod 755 "$BATS_FILE_TMPDIR/sendmail"
}

@test "The mail wrapper sends what the 1.x wrapper sent" {
	e8_fake_sendmail
	app_name="$(v-list-sys-config json | jq -r .config.APP_NAME)"
	from="$(v-list-sys-config json | jq -r .config.FROM_EMAIL)"
	printf 'Line one\nLine <b>two</b> & three\n' | "$HESTIA_DIR/php/bin/php" -d sendmail_path="$BATS_FILE_TMPDIR/sendmail -t -i" \
		"$HESTIA_DIR/lib/mail-wrapper.php" -s "E8 subject" e8@example.invalid
	run cat "$BATS_FILE_TMPDIR/mail/message"
	assert_output --partial "To: e8@example.invalid"
	assert_output --partial "Subject: E8 subject"
	if [ -z "$from" ]; then
		assert_output --regexp "From: $app_name <noreply@[^>]+>"
	fi
	assert_output --partial "Content-Type: multipart/alternative"
	assert_output --partial "Line one<br />"
	assert_output --partial "Line <b>two</b> & three<br />"
}

@test "The mail wrapper skips NOTIFY=no and checks its arguments" {
	e8_fake_sendmail
	echo body | "$HESTIA_DIR/php/bin/php" -d sendmail_path="$BATS_FILE_TMPDIR/sendmail -t -i" \
		"$HESTIA_DIR/lib/mail-wrapper.php" -s "E8 subject" e8@example.invalid no
	assert_not_exists "$BATS_FILE_TMPDIR/mail/message"
	run "$HESTIA_DIR/lib/mail-wrapper.php"
	assert_equal "$status" 3
	assert_output --partial "USAGE: mail-wrapper.php -s SUBJECT EMAIL [NOTIFY]"
}

@test "Webmail clients change a mail password through /reset/mail/" {
	local old new address
	old="Ma1l-$(head -c 9 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
	new="N3w-$old"
	address="e8box@$E8_USER.example"
	v-add-mail-domain "$E8_USER" "$E8_USER.example" > /dev/null
	run v-add-mail-account "$E8_USER" "$E8_USER.example" e8box "$old"
	assert_success

	run curl -sk --data-urlencode "email=$address" --data-urlencode "password=$old" --data-urlencode "new=$new" "$PANEL/reset/mail/"
	assert_output "==ok=="
	run curl -sk --data-urlencode "email=$address" --data-urlencode "password=$old" --data-urlencode "new=$old" "$PANEL/reset/mail/"
	assert_output "error old password does not match"
	run curl -sk --data-urlencode "email=$address" --data-urlencode "password=$new" --data-urlencode "new=$old" "$PANEL/reset/mail/"
	assert_output "==ok=="
	# a proxy header is refused like in 1.x
	run curl -sk -o /dev/null -w '%{http_code}' -H 'X-Forwarded-For: 127.0.0.1' --data-urlencode "email=$address" \
		--data-urlencode "password=$old" --data-urlencode "new=$new" "$PANEL/reset/mail/"
	assert_output "403"
}

@test "Notifications are sanitized with lib/vendor" {
	run v-add-user-notification "$E8_USER" "E8 notice" '<p>ok</p><script>alert(1)</script>'
	assert_success
	run v-list-user-notifications "$E8_USER" json
	assert_output --partial "E8 notice"
	refute_output --partial "<script>"
}
