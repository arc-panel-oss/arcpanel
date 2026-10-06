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

# E1 slice S2: the ARCPANEL_APP_PAGES switch
# (docs/features/specs/2026-10-05-002-e1-s2-dashboard-web-domains-design.md §9)

PAGES_USER="arcbatspages"
PAGES_JAR="$BATS_FILE_TMPDIR/pages.jar"

pages_login() {
    local token
    rm -f "$PAGES_JAR"
    token="$(curl -sk -c "$PAGES_JAR" -b "$PAGES_JAR" "$PANEL/app/login" | grep -o 'name="_token" value="[^"]*"' | head -n1 | sed 's/.*value="\([^"]*\)"/\1/')"
    curl -sk -c "$PAGES_JAR" -b "$PAGES_JAR" -o /dev/null --data-urlencode "_token=$token" \
        --data-urlencode "user=$PAGES_USER" --data-urlencode "password=$PAGES_PASS" "$PANEL/app/login"
}

# status and redirect target of a page for the signed-in test user; like a
# browser it sends the page it came from (the legacy CSRF check wants it)
pages_get() {
    curl -sk -b "$PAGES_JAR" -c "$PAGES_JAR" -e "$PANEL/list/web/" -o /dev/null -w '%{http_code} %{redirect_url}' "$PANEL$1"
}

setup_file() {
    PAGES_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
    export PAGES_PASS
    v-add-user "$PAGES_USER" "$PAGES_PASS" "$PAGES_USER@example.invalid" default > /dev/null
    v-add-web-domain "$PAGES_USER" "$PAGES_USER.example" > /dev/null
    # E7 batch 1: one object per migrated module
    v-add-database "$PAGES_USER" db db "$PAGES_PASS" > /dev/null
    # job id 1 of a new user
    v-add-cron-job "$PAGES_USER" 0 1 '*' '*' '*' 'echo arc' > /dev/null
    # a zone with the records of the default template (record id 1 is its first NS)
    v-add-dns-domain "$PAGES_USER" "$PAGES_USER.dns.example" 198.51.100.10 > /dev/null
    # a mail domain with the account info (v-add-mail-account takes the password itself)
    v-add-mail-domain "$PAGES_USER" "$PAGES_USER.mail.example" > /dev/null
    v-add-mail-account "$PAGES_USER" "$PAGES_USER.mail.example" info "$PAGES_PASS" > /dev/null
}

teardown_file() {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    v-delete-user "$PAGES_USER" > /dev/null 2>&1 || true
}

@test "v-list-sys-config lists ARCPANEL_APP_PAGES" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    run v-list-sys-config json
    assert_success
    assert_output --partial '"ARCPANEL_APP_PAGES": "yes"'
    run v-list-sys-config
    assert_output --partial 'Panel app pages:                  yes'
}

@test "Legacy web pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/web/
    assert_output "302 $PANEL/app/web/domains"
    run pages_get /add/web/
    assert_output "302 $PANEL/app/web/domains/create"
    run pages_get "/edit/web/?domain=$PAGES_USER.example"
    assert_output "302 $PANEL/app/web/domains/$PAGES_USER/$PAGES_USER.example/edit"
    run pages_get "/list/web-log/?domain=$PAGES_USER.example&type=error"
    assert_output "302 $PANEL/app/web/domains/$PAGES_USER/$PAGES_USER.example/logs/error"
    run pages_get /login/
    assert_output "302 $PANEL/app/"
    run pages_get "/app/web/domains/$PAGES_USER/$PAGES_USER.example/edit"
    assert_output "200 "
}

@test "Legacy web pages come back when ARCPANEL_APP_PAGES is no" {
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    run pages_get /list/web/
    assert_output "200 "
    run pages_get /add/web/
    assert_output "200 "
    run pages_get "/edit/web/?domain=$PAGES_USER.example"
    assert_output "200 "
    run pages_get "/list/web-log/?domain=$PAGES_USER.example&type=access"
    assert_output "200 "
    run pages_get /login/
    assert_output "302 $PANEL/list/web/"
    # the panel app pages stay reachable by URL
    run pages_get /app/web/domains
    assert_output "200 "
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

# E7 batch 1 (docs/features/specs/2026-10-06-001-e7-batch1-pages-design.md §4)

@test "Legacy database pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/db/
    assert_output "302 $PANEL/app/db"
    run pages_get /add/db/
    assert_output "302 $PANEL/app/db/create"
    run pages_get "/edit/db/?database=${PAGES_USER}_db"
    assert_output "302 $PANEL/app/db/$PAGES_USER/${PAGES_USER}_db/edit"
    run pages_get "/app/db/$PAGES_USER/${PAGES_USER}_db/edit"
    assert_output "200 "
}

@test "Legacy database pages come back when ARCPANEL_APP_PAGES is no" {
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    run pages_get /list/db/
    assert_output "200 "
    run pages_get /add/db/
    assert_output "200 "
    run pages_get "/edit/db/?database=${PAGES_USER}_db"
    assert_output "200 "
    run pages_get /app/db
    assert_output "200 "
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

@test "Legacy cron pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/cron/
    assert_output "302 $PANEL/app/cron"
    run pages_get /add/cron/
    assert_output "302 $PANEL/app/cron/create"
    run pages_get "/edit/cron/?job=1"
    assert_output "302 $PANEL/app/cron/$PAGES_USER/1/edit"
    run pages_get "/app/cron/$PAGES_USER/1/edit"
    assert_output "200 "
}

@test "Legacy cron pages come back when ARCPANEL_APP_PAGES is no" {
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    run pages_get /list/cron/
    assert_output "200 "
    run pages_get /add/cron/
    assert_output "200 "
    run pages_get "/edit/cron/?job=1"
    assert_output "200 "
    run pages_get /app/cron
    assert_output "200 "
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

@test "Legacy DNS pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    local zone="$PAGES_USER.dns.example"
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/dns/
    assert_output "302 $PANEL/app/dns"
    run pages_get "/list/dns/?domain=$zone"
    assert_output "302 $PANEL/app/dns/$PAGES_USER/$zone/records"
    run pages_get "/list/dns/?domain=$zone&action=view"
    assert_output "302 $PANEL/app/dns/$PAGES_USER/$zone/dnssec"
    run pages_get /add/dns/
    assert_output "302 $PANEL/app/dns/create"
    run pages_get "/add/dns/?domain=$zone"
    assert_output "302 $PANEL/app/dns/$PAGES_USER/$zone/records?add=1"
    run pages_get "/edit/dns/?domain=$zone"
    assert_output "302 $PANEL/app/dns/$PAGES_USER/$zone/edit"
    run pages_get "/edit/dns/?domain=$zone&record_id=1"
    assert_output "302 $PANEL/app/dns/$PAGES_USER/$zone/records?edit=1"
    run pages_get "/app/dns/$PAGES_USER/$zone/records?edit=1"
    assert_output "200 "
    run pages_get "/app/dns/$PAGES_USER/$zone/edit"
    assert_output "200 "
}

@test "Legacy DNS pages come back when ARCPANEL_APP_PAGES is no" {
    local zone="$PAGES_USER.dns.example"
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    run pages_get /list/dns/
    assert_output "200 "
    run pages_get "/list/dns/?domain=$zone"
    assert_output "200 "
    run pages_get /add/dns/
    assert_output "200 "
    run pages_get "/edit/dns/?domain=$zone"
    assert_output "200 "
    run pages_get "/edit/dns/?domain=$zone&record_id=1"
    assert_output "200 "
    run pages_get /app/dns
    assert_output "200 "
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

@test "Legacy mail pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    local domain="$PAGES_USER.mail.example"
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/mail/
    assert_output "302 $PANEL/app/mail"
    run pages_get "/list/mail/?domain=$domain"
    assert_output "302 $PANEL/app/mail/$PAGES_USER/$domain/accounts"
    run pages_get "/list/mail/?domain=$domain&dns=1"
    assert_output "302 $PANEL/app/mail/$PAGES_USER/$domain/dns"
    run pages_get /add/mail/
    assert_output "302 $PANEL/app/mail/create"
    run pages_get "/add/mail/?domain=$domain"
    assert_output "302 $PANEL/app/mail/$PAGES_USER/$domain/accounts/create"
    run pages_get "/edit/mail/?domain=$domain"
    assert_output "302 $PANEL/app/mail/$PAGES_USER/$domain/edit"
    run pages_get "/edit/mail/?domain=$domain&account=info"
    assert_output "302 $PANEL/app/mail/$PAGES_USER/$domain/accounts/info/edit"
    run pages_get "/app/mail/$PAGES_USER/$domain/accounts/info/edit"
    assert_output "200 "
    run pages_get "/app/mail/$PAGES_USER/$domain/dns"
    assert_output "200 "
}

@test "Legacy mail pages come back when ARCPANEL_APP_PAGES is no" {
    local domain="$PAGES_USER.mail.example"
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    run pages_get /list/mail/
    assert_output "200 "
    run pages_get "/list/mail/?domain=$domain"
    assert_output "200 "
    run pages_get "/list/mail/?domain=$domain&dns=1"
    assert_output "200 "
    run pages_get /add/mail/
    assert_output "200 "
    run pages_get "/add/mail/?domain=$domain"
    assert_output "200 "
    run pages_get "/edit/mail/?domain=$domain"
    assert_output "200 "
    run pages_get "/edit/mail/?domain=$domain&account=info"
    assert_output "200 "
    run pages_get /app/mail
    assert_output "200 "
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

@test "Legacy backup pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/backup/
    assert_output "302 $PANEL/app/backups/$PAGES_USER"
    run pages_get "/list/backup/?backup=$PAGES_USER.2026-10-06_05-10-00.tar"
    assert_output "302 $PANEL/app/backups/$PAGES_USER/archives/$PAGES_USER.2026-10-06_05-10-00.tar"
    run pages_get /list/backup/incremental/
    assert_output "302 $PANEL/app/backups/$PAGES_USER/snapshots"
    run pages_get "/list/backup/incremental/?snapshot=0123abcd"
    assert_output "302 $PANEL/app/backups/$PAGES_USER/snapshots/0123abcd"
    run pages_get "/list/backup/incremental/?snapshot=0123abcd&browse=yes"
    assert_output "302 $PANEL/app/files/$PAGES_USER?snapshot=0123abcd"
    run pages_get /edit/backup/exclusions/
    assert_output "302 $PANEL/app/backups/$PAGES_USER/exclusions"
    run pages_get /list/backup/exclusions/
    assert_output "302 $PANEL/app/backups/$PAGES_USER/exclusions"
    run pages_get "/app/backups/$PAGES_USER"
    assert_output "200 "
    run pages_get "/app/backups/$PAGES_USER/exclusions"
    assert_output "200 "
}

@test "Legacy backup pages come back when ARCPANEL_APP_PAGES is no" {
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    run pages_get /list/backup/
    assert_output "200 "
    run pages_get /list/backup/exclusions/
    assert_output "200 "
    run pages_get /edit/backup/exclusions/
    assert_output "200 "
    run pages_get "/app/backups/$PAGES_USER"
    assert_output "200 "
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

# E7 batch 2 (docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §4)

@test "Legacy firewall pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/firewall/
    assert_output "302 $PANEL/app/server/firewall"
    run pages_get /add/firewall/
    assert_output "302 $PANEL/app/server/firewall/create"
    run pages_get "/edit/firewall/?rule=2"
    assert_output "302 $PANEL/app/server/firewall/2/edit"
    run pages_get /list/firewall/ipset/
    assert_output "302 $PANEL/app/server/firewall/ipsets"
    run pages_get /add/firewall/ipset/
    assert_output "302 $PANEL/app/server/firewall/ipsets/create"
    run pages_get /list/firewall/banlist/
    assert_output "302 $PANEL/app/server/firewall/bans"
    run pages_get /add/firewall/banlist/
    assert_output "302 $PANEL/app/server/firewall/bans/create"
    # the test user is no administrator
    run pages_get /app/server/firewall
    assert_output "403 "
}

@test "Legacy firewall pages come back when ARCPANEL_APP_PAGES is no" {
    v-change-sys-config-value ARCPANEL_APP_PAGES no
    pages_login
    # legacy sends non-administrators to the user list
    run pages_get /list/firewall/
    assert_output "302 $PANEL/list/user/"
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
}

@test "Legacy IP pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/ip/
    assert_output "302 $PANEL/app/server/ips"
    run pages_get /add/ip/
    assert_output "302 $PANEL/app/server/ips/create"
    run pages_get "/edit/ip/?ip=10.0.0.10"
    assert_output "302 $PANEL/app/server/ips/10.0.0.10/edit"
    run pages_get /app/server/ips
    assert_output "403 "
}

@test "Legacy key pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/access-key/
    assert_output "302 $PANEL/app/access-keys/$PAGES_USER"
    run pages_get "/list/access-key/?key=AbCdEfGhIjKlMnOpQr12"
    assert_output "302 $PANEL/app/access-keys/$PAGES_USER/AbCdEfGhIjKlMnOpQr12"
    run pages_get /add/access-key/
    assert_output "302 $PANEL/app/access-keys/$PAGES_USER/create"
    run pages_get /list/key/
    assert_output "302 $PANEL/app/ssh-keys/$PAGES_USER"
    run pages_get /add/key/
    assert_output "302 $PANEL/app/ssh-keys/$PAGES_USER"
    run pages_get "/app/ssh-keys/$PAGES_USER"
    assert_output "200 "
}

@test "Legacy log pages redirect to the panel app when ARCPANEL_APP_PAGES is yes" {
    v-change-sys-config-value ARCPANEL_APP_PAGES yes
    pages_login
    run pages_get /list/log/
    assert_output "302 $PANEL/app/logs/$PAGES_USER"
    run pages_get /list/log/auth/
    assert_output "302 $PANEL/app/logs/$PAGES_USER/auth"
    run pages_get "/app/logs/$PAGES_USER"
    assert_output "200 "
    run pages_get "/app/logs/$PAGES_USER/auth"
    assert_output "200 "
}

@test "Panel app build ships hashed assets and fonts" {
    run cat /usr/local/hestia/web-app/public/build/manifest.json
    assert_success
    assert_output --regexp '"app.css": "app\.[0-9a-f]{10}\.css"'
    assert_file_exists /usr/local/hestia/web-app/public/build/fonts/inter-latin-400-normal.woff2
}
