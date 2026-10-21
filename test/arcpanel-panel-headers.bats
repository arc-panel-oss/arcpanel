#!/usr/bin/env bats
# Browser security headers of the panel and the tool ports (E39 W5 spec §9:
# L7, L8, N21, N32). Run as root on a test server; the suite turns HSTS off
# again and removes its temporary NGINX server.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
NGINX_CONF="$ARCPANEL/nginx/conf/nginx.conf"
COOKIE_PROBE=/etc/nginx/conf.d/arcbats-cookie-probe.conf

panel_port() {
    sed -n 's/^\s*listen\s\+\([0-9]\+\) ssl;.*/\1/p' "$NGINX_CONF" | head -n1
}

# Response headers of a GET to the panel, lower case
headers() {
    curl -sk -o /dev/null -D - "https://127.0.0.1:$(panel_port)$1" | tr -d '\r' | tr '[:upper:]' '[:lower:]'
}

panel_lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source \$ARCPANEL/func/main.sh; source \$ARCPANEL/func/panel-headers.sh; $*"
}

teardown_file() {
    v-change-sys-arcpanel-hsts no > /dev/null 2>&1 || true
    rm -f "$COOKIE_PROBE"
    nginx -t > /dev/null 2>&1 && systemctl reload nginx > /dev/null 2>&1 || true
}

@test "nginx.conf: every location with an add_header of its own includes a header snippet" {
    run awk '
        /^[ \t]*location[ \t]/ { inloc = 1; depth = 0; name = $0; add = 0; inc = 0 }
        inloc {
            if ($0 ~ /add_header/) add = 1
            if ($0 ~ /include[ \t]+security-headers(-files)?\.conf;/) inc = 1
            depth += gsub(/\{/, "{") - gsub(/\}/, "}")
            if (depth == 0 && $0 ~ /\}/) {
                if (add && !inc) print "missing in: " name
                inloc = 0
            }
        }' "$NGINX_CONF"
    assert_output ''
    run grep -c 'include             security-headers.conf;' "$NGINX_CONF"
    assert_output 1
    run grep -i 'X-XSS-Protection' "$NGINX_CONF"
    assert_failure
}

@test "panel: pages, assets, errors and the API carry the security headers (E39 W5 L7, N21)" {
    v-update-sys-panel-headers
    asset=$(jq -r '."app.js"' "$ARCPANEL/web-app/public/build/manifest.json")
    for path in /login "/build/$asset" /nothing.sql /api/v2/users; do
        run headers "$path"
        assert_line 'x-content-type-options: nosniff'
        assert_line 'x-frame-options: sameorigin'
        assert_line 'referrer-policy: same-origin'
        assert_line --partial "content-security-policy: default-src 'self'; script-src 'self' 'unsafe-eval';"
        refute_line --partial 'x-xss-protection'
    done
}

@test "panel: forms may post to the tool ports and nowhere else (E39 W5 §9.2)" {
    webmail=$(v-list-sys-config json | jq -r '.config.WEBMAIL_PORT')
    run headers /login
    assert_line --partial "form-action 'self'"
    if [ -n "$webmail" ]; then
        assert_line --partial "https://127.0.0.1:$webmail"
    fi
    assert_line --partial "frame-ancestors 'self'; object-src 'none'; base-uri 'none'"
}

@test "v-change-sys-arcpanel-hsts: refused with an untrusted certificate, sent when on, gone when off" {
    if ! panel_lib panel_cert_trusted; then
        run v-change-sys-arcpanel-hsts yes
        assert_failure 10
        assert_output --partial 'not trusted'
    fi
    run v-change-sys-arcpanel-hsts maybe
    assert_failure 2

    # What yes writes, without a trusted certificate on the test server
    panel_lib 'panel_hsts_write yes && panel_nginx_reload'
    run headers /login
    assert_line 'strict-transport-security: max-age=86400'
    run v-change-sys-arcpanel-hsts no
    assert_success
    run headers /login
    refute_line --partial 'strict-transport-security'
    run bash -c "v-list-sys-config json | jq -r '.config.PANEL_HSTS'"
    assert_output 'no'
}

@test "tool ports: the security headers, and the panel's session cookies never reach the tools (E39 W5 N21, N32)" {
    v-update-sys-tool-ports yes > /dev/null
    for file in /etc/nginx/conf.d/arcpanel-*-port.conf; do
        [ -f "$file" ] || continue
        run grep -c 'add_header X-Content-Type-Options "nosniff" always;' "$file"
        assert_output 1
        port=$(sed -n 's/^\s*listen\s\+\([0-9]\+\) ssl;/\1/p' "$file" | head -n1)
        run bash -c "curl -sk -o /dev/null -D - https://127.0.0.1:$port/ | tr -d '\r' | tr '[:upper:]' '[:lower:]'"
        assert_line 'x-content-type-options: nosniff'
        assert_line 'referrer-policy: same-origin'
    done
    for file in /etc/nginx/conf.d/phpmyadmin.inc /etc/nginx/conf.d/arcpanel-webmail-port.conf; do
        [ -f "$file" ] || continue
        run grep -c 'fastcgi_param HTTP_COOKIE     $arcpanel_tool_cookie;' "$file"
        assert_output 1
    done

    # The map itself, through a throw-away server of the system NGINX
    printf 'server {\n\tlisten 127.0.0.1:18099;\n\tlocation / { return 200 "[$arcpanel_tool_cookie]"; }\n}\n' > "$COOKIE_PROBE"
    nginx -t > /dev/null 2>&1
    systemctl reload nginx
    sleep 1
    run curl -s -H 'Cookie: a=1; arcpanel_session=SECRET1; ARCPANELSID=SECRET2; b=2' http://127.0.0.1:18099/
    assert_output '[a=1; b=2]'
    run curl -s -H 'Cookie: arcpanel_session=SECRET1' http://127.0.0.1:18099/
    assert_output '[]'
    run curl -s -H 'Cookie: xarcpanel_session=keep; c=3' http://127.0.0.1:18099/
    assert_output '[xarcpanel_session=keep; c=3]'
    rm -f "$COOKIE_PROBE"
    systemctl reload nginx
}

# Run PHP code through the panel's own pool and print what it echoes
pool_run() {
    local probe
    probe=$(mktemp /tmp/arcbats-probe.XXXXXX.php)
    printf '<?php %s\n' "$1" > "$probe"
    chmod 644 "$probe"
    python3 "$BATS_TEST_DIRNAME/fcgi-probe.py" /run/arcpanel-php.sock "$probe"
    rm -f "$probe"
}

@test "panel pool: open_basedir and disable_functions are in force (E39 W5 L15)" {
    run pool_run 'echo ini_get("open_basedir"), "\n";'
    assert_output --partial '/usr/local/arcpanel/web-app/:/usr/local/arcpanel/lib/:/usr/local/arcpanel/data/:/etc/arcpanel/:/tmp/:'
    run pool_run 'var_dump(@file_get_contents("/etc/passwd"), @file_get_contents("/usr/local/arcpanel/func/main.sh"), is_dir("/usr/local/arcpanel/web-app"));'
    assert_output --partial 'bool(false)
bool(false)
bool(true)'
    run pool_run 'foreach (["system", "passthru", "shell_exec", "popen", "dl", "pcntl_fork", "exec", "proc_open"] as $f) echo $f, "=", function_exists($f) ? "on" : "off", "\n";'
    assert_line 'system=off'
    assert_line 'passthru=off'
    assert_line 'shell_exec=off'
    assert_line 'popen=off'
    assert_line 'dl=off'
    assert_line 'pcntl_fork=off'
    assert_line 'exec=on'
    assert_line 'proc_open=on'
    run bash -c "curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:$(panel_port)/login"
    assert_output '200'
}

@test "panel_php_basedir_write: the backup folder of arcpanel.conf ends the list, an odd one falls back to /backup/" {
    tmp=$(mktemp -d)
    mkdir -p "$tmp/php/etc" "$tmp/conf"
    cp "$ARCPANEL/php/etc/php-fpm.conf" "$tmp/php/etc/php-fpm.conf"
    echo "BACKUP='/srv/backups'" > "$tmp/conf/arcpanel.conf"
    run bash -c "ARCPANEL=$tmp; source /usr/local/arcpanel/func/panel-headers.sh; panel_php_basedir_write; echo \$PANEL_PHP_CHANGED; panel_php_basedir_write; echo \$PANEL_PHP_CHANGED"
    assert_output 'yes
no'
    run grep '^php_admin_value\[open_basedir\]' "$tmp/php/etc/php-fpm.conf"
    assert_output "php_admin_value[open_basedir] = $tmp/web-app/:$tmp/lib/:$tmp/data/:/etc/arcpanel/:/tmp/:/srv/backups/"
    echo "BACKUP='/srv/a|b:/'" > "$tmp/conf/arcpanel.conf"
    bash -c "ARCPANEL=$tmp; source /usr/local/arcpanel/func/panel-headers.sh; panel_php_basedir_write"
    run grep -c ':/tmp/:/backup/$' "$tmp/php/etc/php-fpm.conf"
    assert_output 1

    # Extra folders of the administrator; comments and odd lines are left out
    printf '# extra folders\n/srv/media\n/srv/a:b\n/opt/shared/\n' > "$tmp/extra.conf"
    bash -c "ARCPANEL=$tmp; PANEL_PHP_BASEDIR_EXTRA=$tmp/extra.conf; source /usr/local/arcpanel/func/panel-headers.sh; panel_php_basedir_write"
    run grep -c ':/tmp/:/backup/:/srv/media/:/opt/shared/$' "$tmp/php/etc/php-fpm.conf"
    assert_output 1
    rm -rf "$tmp"
}
