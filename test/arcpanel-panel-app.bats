#!/usr/bin/env bats
# ArcPanel panel app plumbing (E1 slice S1,
# docs/features/specs/2026-10-05-001-e1-laravel-foundation-slice1-design.md).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

PANEL="https://127.0.0.1:8083"

app_key_hash() {
    grep '^APP_KEY=' /etc/arcpanel/arcpanel.env | sha256sum
}

@test "v-add-sys-arcpanel writes a private configuration file" {
    run v-add-sys-arcpanel
    assert_success
    assert_file_exists /etc/arcpanel/arcpanel.env
    run stat -c '%a %U:%G' /etc/arcpanel/arcpanel.env
    assert_output "640 root:hestiaweb"
    run grep -c '^APP_KEY=base64:' /etc/arcpanel/arcpanel.env
    assert_output "1"
}

@test "v-add-sys-arcpanel can run again and keeps the key" {
    before="$(app_key_hash)"
    run v-add-sys-arcpanel
    assert_success
    assert_equal "$(app_key_hash)" "$before"
}

@test "Panel app storage is writable by hestiaweb only" {
    run stat -c '%a %U' /usr/local/hestia/web-app/storage/logs /usr/local/hestia/web-app/bootstrap/cache
    assert_output "750 hestiaweb
750 hestiaweb"
}

@test "Command allow-list is generated from the installed scripts" {
    run grep -c "'v-list-user'" /usr/local/hestia/web-app/config/arc-commands.php
    assert_output "1"
}

@test "hestiaweb may run commands through the ArcPanel path" {
    run runuser -u hestiaweb -- sudo -n /usr/local/arcpanel/bin/v-list-sys-config json
    assert_success
    assert_output --partial '"ARCPANEL_VERSION"'
}

@test "Panel nginx configuration is valid" {
    run /usr/local/hestia/nginx/sbin/hestia-nginx -t
    assert_success
}

@test "Health endpoint answers without a session" {
    run curl -sk "$PANEL/app/_health"
    assert_success
    assert_output --partial '"status":"ok"'
}

@test "Panel app root works with the framework caches built" {
    run curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "$PANEL/app/"
    assert_output "302 $PANEL/app/login"
}

@test "Legacy login redirects guests to the panel app when the switch is on" {
    v-change-sys-config-value ARCPANEL_APP_LOGIN yes
    run curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "$PANEL/login/"
    assert_output "302 $PANEL/app/login"
}

@test "Legacy login form comes back when the switch is off" {
    v-change-sys-config-value ARCPANEL_APP_LOGIN no
    run curl -sk -o /dev/null -w '%{http_code}' "$PANEL/login/"
    v-change-sys-config-value ARCPANEL_APP_LOGIN yes
    assert_output "200"
}

@test "Session cookie is ARCPANELSID" {
    run curl -sk -D - -o /dev/null "$PANEL/list/user/"
    assert_output --partial 'ARCPANELSID='
    refute_output --partial 'HESTIASID='
}
