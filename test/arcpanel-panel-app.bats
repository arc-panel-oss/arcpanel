#!/usr/bin/env bats
# ArcPanel panel app plumbing (E1 slice S1,
# docs/features/specs/2026-10-05-001-e1-laravel-foundation-slice1-design.md).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
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
    assert_output "640 root:arcpanelweb"
    run grep -c '^APP_KEY=base64:' /etc/arcpanel/arcpanel.env
    assert_output "1"
}

@test "v-add-sys-arcpanel can run again and keeps the key" {
    before="$(app_key_hash)"
    run v-add-sys-arcpanel
    assert_success
    assert_equal "$(app_key_hash)" "$before"
}

@test "A change of arcpanel.conf writes a new config stamp that only arcpanelweb reads" {
    stamp=/usr/local/arcpanel/data/sys-config/stamp
    before=$(cat "$stamp" 2> /dev/null || true)
    debug=$(grep -o "^DEBUG_MODE='[a-z]*'" /usr/local/arcpanel/conf/arcpanel.conf | cut -d"'" -f2 || true)
    run v-change-sys-config-value DEBUG_MODE "${debug:-false}"
    assert_success
    run stat -c '%a %U:%G' /usr/local/arcpanel/data/sys-config "$stamp"
    assert_output "750 root:arcpanelweb
640 root:arcpanelweb"
    run runuser -u arcpanelweb -- cat "$stamp"
    assert_success
    assert_output --regexp '^[0-9]+\.[0-9]+$'
    refute_output "$before"
    # arcpanelweb can read the stamp but cannot replace it
    run runuser -u arcpanelweb -- touch /usr/local/arcpanel/data/sys-config/other
    assert_failure
    run runuser -u arcpanelweb -- sh -c "echo 1 > $stamp"
    assert_failure
    # the commands that write arcpanel.conf without v-change-sys-config-value stamp too
    before=$(cat "$stamp")
    language=$(grep -o "^LANGUAGE='[a-z_A-Z-]*'" /usr/local/arcpanel/conf/arcpanel.conf | cut -d"'" -f2 || true)
    run v-change-sys-language "${language:-en}"
    assert_success
    assert_not_equal "$(cat "$stamp")" "$before"
}

@test "API v2 follows a change of the allow list at the next request" {
    api_system=$(grep -o "^API_SYSTEM='[0-9]*'" /usr/local/arcpanel/conf/arcpanel.conf | cut -d"'" -f2 || true)
    allowed=$(grep -o "^API_ALLOWED_IP='[^']*'" /usr/local/arcpanel/conf/arcpanel.conf | cut -d"'" -f2 || true)
    api_status() {
        curl -sk -o /dev/null -w '%{http_code}' "$PANEL/api/v2/me"
    }
    v-change-sys-config-value API_SYSTEM 1
    v-change-sys-config-value API_ALLOWED_IP 192.0.2.1
    # the panel has the list without 127.0.0.1 in its cache now
    run api_status
    assert_output "403"
    v-add-sys-api-ip 127.0.0.1
    # past the allow list, refused for the missing key
    run api_status
    assert_output "401"
    v-delete-sys-api-ip 127.0.0.1
    run api_status
    assert_output "403"
    v-change-sys-config-value API_ALLOWED_IP "$allowed"
    v-change-sys-config-value API_SYSTEM "${api_system:-0}"
}

@test "Panel app storage is writable by arcpanelweb only" {
    run stat -c '%a %U' /usr/local/arcpanel/web-app/storage/logs /usr/local/arcpanel/web-app/bootstrap/cache
    assert_output "750 arcpanelweb
750 arcpanelweb"
}

@test "Command allow-list is generated from the installed scripts" {
    run grep -c "'v-list-user'" /usr/local/arcpanel/web-app/config/arc-commands.php
    assert_output "1"
}

@test "arcpanelweb may run commands through the ArcPanel path" {
    run runuser -u arcpanelweb -- sudo -n /usr/local/arcpanel/bin/v-list-sys-config json
    assert_success
    assert_output --partial '"VERSION"'
}

@test "Panel nginx configuration is valid" {
    run /usr/local/arcpanel/nginx/sbin/arcpanel-nginx -t
    assert_success
}

@test "Health endpoint answers without a session" {
    run curl -sk "$PANEL/_health"
    assert_success
    assert_output --partial '"status":"ok"'
}

@test "Panel app root works with the framework caches built" {
    run curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "$PANEL/"
    assert_output "302 $PANEL/login"
}

@test "Session cookie is ARCPANELSID" {
    pages_login
    run cat "$PAGES_JAR"
    assert_output --partial "ARCPANELSID"
    refute_output --partial "HESTIASID"
}

# Old URLs: the legacy page paths answer 301, or 302 when the target depends
# on the signed-in user (E8 spec §5.3; first written for the E1 slice S2 switch)
# (docs/features/specs/2026-10-05-002-e1-s2-dashboard-web-domains-design.md §9)

PAGES_USER="arcbatspages"
PAGES_JAR="$BATS_FILE_TMPDIR/pages.jar"

pages_login() {
    local token
    rm -f "$PAGES_JAR"
    token="$(curl -sk -c "$PAGES_JAR" -b "$PAGES_JAR" "$PANEL/login" | grep -o 'name="_token" value="[^"]*"' | head -n1 | sed 's/.*value="\([^"]*\)"/\1/')"
    curl -sk -c "$PAGES_JAR" -b "$PAGES_JAR" -o /dev/null --data-urlencode "_token=$token" \
        --data-urlencode "user=$PAGES_USER" --data-urlencode "password=$PAGES_PASS" "$PANEL/login"
}

# status and redirect target of a page for the signed-in test user
pages_get() {
    curl -sk -b "$PAGES_JAR" -c "$PAGES_JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$PANEL$1"
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
    v-delete-user "$PAGES_USER" > /dev/null 2>&1 || true
}

@test "Legacy web pages redirect to the panel app" {
    pages_login
    run pages_get /list/web/
    assert_output "301 $PANEL/web/domains"
    run pages_get /add/web/
    assert_output "301 $PANEL/web/domains/create"
    run pages_get "/edit/web/?domain=$PAGES_USER.example"
    assert_output "302 $PANEL/web/domains/$PAGES_USER/$PAGES_USER.example/edit"
    run pages_get "/list/web-log/?domain=$PAGES_USER.example&type=error"
    assert_output "302 $PANEL/web/domains/$PAGES_USER/$PAGES_USER.example/logs/error"
    run pages_get /login/
    assert_output "302 $PANEL/"
    run pages_get "/web/domains/$PAGES_USER/$PAGES_USER.example/edit"
    assert_output "200 "
}


# E7 batch 1 (docs/features/specs/2026-10-06-001-e7-batch1-pages-design.md §4)

@test "Legacy database pages redirect to the panel app" {
    pages_login
    run pages_get /list/db/
    assert_output "301 $PANEL/db"
    run pages_get /add/db/
    assert_output "301 $PANEL/db/create"
    run pages_get "/edit/db/?database=${PAGES_USER}_db"
    assert_output "302 $PANEL/db/$PAGES_USER/${PAGES_USER}_db/edit"
    run pages_get "/db/$PAGES_USER/${PAGES_USER}_db/edit"
    assert_output "200 "
}

@test "Legacy cron pages redirect to the panel app" {
    pages_login
    run pages_get /list/cron/
    assert_output "301 $PANEL/cron"
    run pages_get /add/cron/
    assert_output "301 $PANEL/cron/create"
    run pages_get "/edit/cron/?job=1"
    assert_output "302 $PANEL/cron/$PAGES_USER/1/edit"
    run pages_get "/cron/$PAGES_USER/1/edit"
    assert_output "200 "
}

@test "Legacy DNS pages redirect to the panel app" {
    local zone="$PAGES_USER.dns.example"
    pages_login
    run pages_get /list/dns/
    assert_output "302 $PANEL/dns"
    run pages_get "/list/dns/?domain=$zone"
    assert_output "302 $PANEL/dns/$PAGES_USER/$zone/records"
    run pages_get "/list/dns/?domain=$zone&action=view"
    assert_output "302 $PANEL/dns/$PAGES_USER/$zone/dnssec"
    run pages_get /add/dns/
    assert_output "302 $PANEL/dns/create"
    run pages_get "/add/dns/?domain=$zone"
    assert_output "302 $PANEL/dns/$PAGES_USER/$zone/records?add=1"
    run pages_get "/edit/dns/?domain=$zone"
    assert_output "302 $PANEL/dns/$PAGES_USER/$zone/edit"
    run pages_get "/edit/dns/?domain=$zone&record_id=1"
    assert_output "302 $PANEL/dns/$PAGES_USER/$zone/records?edit=1"
    run pages_get "/dns/$PAGES_USER/$zone/records?edit=1"
    assert_output "200 "
    run pages_get "/dns/$PAGES_USER/$zone/edit"
    assert_output "200 "
}

@test "Legacy mail pages redirect to the panel app" {
    local domain="$PAGES_USER.mail.example"
    pages_login
    run pages_get /list/mail/
    assert_output "302 $PANEL/mail"
    run pages_get "/list/mail/?domain=$domain"
    assert_output "302 $PANEL/mail/$PAGES_USER/$domain/accounts"
    run pages_get "/list/mail/?domain=$domain&dns=1"
    assert_output "302 $PANEL/mail/$PAGES_USER/$domain/dns"
    run pages_get /add/mail/
    assert_output "302 $PANEL/mail/create"
    run pages_get "/add/mail/?domain=$domain"
    assert_output "302 $PANEL/mail/$PAGES_USER/$domain/accounts/create"
    run pages_get "/edit/mail/?domain=$domain"
    assert_output "302 $PANEL/mail/$PAGES_USER/$domain/edit"
    run pages_get "/edit/mail/?domain=$domain&account=info"
    assert_output "302 $PANEL/mail/$PAGES_USER/$domain/accounts/info/edit"
    run pages_get "/mail/$PAGES_USER/$domain/accounts/info/edit"
    assert_output "200 "
    run pages_get "/mail/$PAGES_USER/$domain/dns"
    assert_output "200 "
}

@test "Legacy backup pages redirect to the panel app" {
    pages_login
    run pages_get /list/backup/
    assert_output "302 $PANEL/backups/$PAGES_USER"
    run pages_get "/list/backup/?backup=$PAGES_USER.2026-10-06_05-10-00.tar"
    assert_output "302 $PANEL/backups/$PAGES_USER/archives/$PAGES_USER.2026-10-06_05-10-00.tar"
    run pages_get /list/backup/incremental/
    assert_output "302 $PANEL/backups/$PAGES_USER/snapshots"
    run pages_get "/list/backup/incremental/?snapshot=0123abcd"
    assert_output "302 $PANEL/backups/$PAGES_USER/snapshots/0123abcd"
    run pages_get "/list/backup/incremental/?snapshot=0123abcd&browse=yes"
    assert_output "302 $PANEL/files/$PAGES_USER?snapshot=0123abcd"
    run pages_get /edit/backup/exclusions/
    assert_output "302 $PANEL/backups/$PAGES_USER/exclusions"
    run pages_get /list/backup/exclusions/
    assert_output "302 $PANEL/backups/$PAGES_USER/exclusions"
    run pages_get "/backups/$PAGES_USER"
    assert_output "200 "
    run pages_get "/backups/$PAGES_USER/exclusions"
    assert_output "200 "
}


# E7 batch 2 (docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §4)

@test "Legacy firewall pages redirect to the panel app" {
    pages_login
    run pages_get /list/firewall/
    assert_output "301 $PANEL/server/firewall"
    run pages_get /add/firewall/
    assert_output "301 $PANEL/server/firewall/create"
    run pages_get "/edit/firewall/?rule=2"
    assert_output "301 $PANEL/server/firewall/2/edit"
    run pages_get /list/firewall/ipset/
    assert_output "301 $PANEL/server/firewall/ipsets"
    run pages_get /add/firewall/ipset/
    assert_output "301 $PANEL/server/firewall/ipsets/create"
    run pages_get /list/firewall/banlist/
    assert_output "301 $PANEL/server/firewall/bans"
    run pages_get /add/firewall/banlist/
    assert_output "301 $PANEL/server/firewall/bans/create"
    # the test user is no administrator
    run pages_get /server/firewall
    assert_output "403 "
}

@test "Legacy IP pages redirect to the panel app" {
    pages_login
    run pages_get /list/ip/
    assert_output "301 $PANEL/server/ips"
    run pages_get /add/ip/
    assert_output "301 $PANEL/server/ips/create"
    run pages_get "/edit/ip/?ip=10.0.0.10"
    assert_output "301 $PANEL/server/ips/10.0.0.10/edit"
    run pages_get /server/ips
    assert_output "403 "
}

@test "Legacy key pages redirect to the panel app" {
    pages_login
    run pages_get /list/access-key/
    assert_output "302 $PANEL/access-keys/$PAGES_USER"
    run pages_get "/list/access-key/?key=AbCdEfGhIjKlMnOpQr12"
    assert_output "302 $PANEL/access-keys/$PAGES_USER/AbCdEfGhIjKlMnOpQr12"
    run pages_get /add/access-key/
    assert_output "302 $PANEL/access-keys/$PAGES_USER/create"
    run pages_get /list/key/
    assert_output "302 $PANEL/ssh-keys/$PAGES_USER"
    run pages_get /add/key/
    assert_output "302 $PANEL/ssh-keys/$PAGES_USER"
    run pages_get "/ssh-keys/$PAGES_USER"
    assert_output "200 "
}

@test "Legacy log pages redirect to the panel app" {
    pages_login
    run pages_get /list/log/
    assert_output "302 $PANEL/logs/$PAGES_USER"
    run pages_get /list/log/auth/
    assert_output "302 $PANEL/logs/$PAGES_USER/auth"
    run pages_get "/logs/$PAGES_USER"
    assert_output "200 "
    run pages_get "/logs/$PAGES_USER/auth"
    assert_output "200 "
}

@test "Legacy statistics and graph pages redirect to the panel app" {
    pages_login
    run pages_get /list/stats/
    assert_output "302 $PANEL/stats"
    run pages_get "/list/rrd/?period=weekly"
    assert_output "301 $PANEL/server/rrd?period=weekly"
    run pages_get /stats
    assert_output "200 "
    run pages_get /server/rrd
    assert_output "403 "
}

@test "Legacy terminal and Quick Install pages redirect to the panel app" {
    pages_login
    run pages_get /list/terminal/
    assert_output "301 $PANEL/terminal"
    run pages_get "/add/webapp/?domain=$PAGES_USER.example"
    assert_output "302 $PANEL/web/domains/$PAGES_USER/$PAGES_USER.example/install"
    run pages_get "/add/webapp/?domain=$PAGES_USER.example&app=DokuWiki"
    assert_output "302 $PANEL/web/domains/$PAGES_USER/$PAGES_USER.example/install/DokuWiki"
    run pages_get "/web/domains/$PAGES_USER/$PAGES_USER.example/install"
    assert_output "200 "
}

# E7 batch 3 (docs/features/specs/2026-10-06-003-e7-batch3-pages-design.md §4)

@test "Legacy services pages redirect to the panel app" {
    pages_login
    run pages_get /list/server/
    assert_output "301 $PANEL/server/services"
    run pages_get "/list/server/?cpu"
    assert_output "301 $PANEL/server/services/status/cpu"
    run pages_get "/list/server/?mem"
    assert_output "301 $PANEL/server/services/status/mem"
    run pages_get /server/services
    assert_output "403 "
}

@test "Legacy server settings pages redirect to the panel app" {
    pages_login
    run pages_get /edit/server/
    assert_output "301 $PANEL/server/settings"
    run pages_get /edit/server/whitelabel/
    assert_output "301 $PANEL/server/settings/white-label"
    run pages_get /edit/server/nginx/
    assert_output "301 $PANEL/server/services/nginx/config"
    run pages_get /edit/server/php-fpm/
    assert_output "301 $PANEL/server/services/php-fpm/config"
    run pages_get /list/updates/
    assert_output "301 $PANEL/server/updates"
    run pages_get /server/settings/general
    assert_output "403 "
}

@test "Legacy search and CSR pages redirect to the panel app" {
    pages_login
    run pages_get "/search/?q=$PAGES_USER"
    assert_output "200 "
    run pages_get "/generate/ssl/?domain=$PAGES_USER.example"
    assert_output "302 $PANEL/web/domains/$PAGES_USER/$PAGES_USER.example/edit#ssl"
    run pages_get "/search?q=$PAGES_USER"
    assert_output "200 "
}

@test "Legacy reset pages redirect guests to the panel app" {
    policy="$(grep -o "^POLICY_SYSTEM_PASSWORD_RESET='[a-z]*'" /usr/local/arcpanel/conf/arcpanel.conf | cut -d"'" -f2 || true)"
    v-change-sys-config-value POLICY_SYSTEM_PASSWORD_RESET yes
    guest_get() {
        # the 1.x reset paths with a trailing slash are the panel pages
        curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "$PANEL$1"
    }
    run guest_get /reset/
    assert_output "200 "
    run guest_get "/reset/?action=code&user=$PAGES_USER"
    assert_output "302 $PANEL/reset/code?user=$PAGES_USER"
    # the 1.x e-mail link: user and code move into the session, the redirect
    # carries no query (E39 W2 D9)
    run guest_get "/reset/?action=confirm&user=$PAGES_USER&code=Abc123"
    assert_output "302 $PANEL/reset/confirm"
    run guest_get /reset2fa/
    assert_output "200 "
    run guest_get /reset2fa
    v-change-sys-config-value POLICY_SYSTEM_PASSWORD_RESET "${policy:-yes}"
    assert_output "200 "
}

@test "Panel app build ships hashed assets and fonts" {
    run cat /usr/local/arcpanel/web-app/public/build/manifest.json
    assert_success
    assert_output --regexp '"app.css": "app\.[0-9a-f]{10}\.css"'
    assert_file_exists /usr/local/arcpanel/web-app/public/build/fonts/inter-latin-400-normal.woff2
}

@test "The services list shows the panel's own unit" {
    run bash -c "v-list-sys-services json | jq -r '.arcpanel | \"\(.SYSTEM) \(.STATE)\"'"
    assert_output "panel web server running"
}

@test "Restarting the panel answers first and restarts it a moment later" {
    before=$(systemctl show arcpanel -p ActiveEnterTimestampMonotonic --value)
    start=$(date +%s%N)
    run v-restart-service arcpanel yes
    assert_success
    # The command does not wait for the restart, which would end the request
    # of the page or the API call that asked for it
    [ $((($(date +%s%N) - start) / 1000000)) -lt 1500 ]
    assert_equal "$(systemctl show arcpanel -p ActiveEnterTimestampMonotonic --value)" "$before"
    for _ in $(seq 1 30); do
        now=$(systemctl show arcpanel -p ActiveEnterTimestampMonotonic --value)
        [ "$now" != "$before" ] && break
        sleep 1
    done
    [ "$now" != "$before" ]
    for _ in $(seq 1 30); do
        curl -sk "$PANEL/_health" | grep -q '"status":"ok"' && break
        sleep 1
    done
    run curl -sk "$PANEL/_health"
    assert_output --partial '"status":"ok"'
}
