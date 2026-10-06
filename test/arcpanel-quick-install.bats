#!/usr/bin/env bats
# Quick Install commands (E7 batch 2,
# docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §10.1).
# Run as root on a test server with internet access (DokuWiki is
# downloaded from dokuwiki.org).

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
QI_USER=tqiuser
QI_OTHER=tqiother
QI_DOMAIN=tqi-wiki.example
SECRET_PASS="Qi-$(date +%s)-NeverLogged"

# Local address of the domain (web.conf may hold the NAT address)
web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$ARCPANEL/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$ARCPANEL"/data/ips/* | head -n1)"
    fi
}

# Wait until the install of the user is no longer running; print its status
wait_task() {
    local status i
    for i in $(seq 1 120); do
        status=$(v-list-user-quick-install-task "$1" 10 json | jq -r .STATUS)
        if [ "$status" != running ]; then
            echo "$status"
            return
        fi
        sleep 2
    done
    echo timeout
}

options_file() {
    local file
    file=$(mktemp)
    printf '%s' "$1" > "$file"
    echo "$file"
}

setup_file() {
    for user in "$QI_USER" "$QI_OTHER"; do
        v-delete-user "$user" > /dev/null 2>&1 || true
        v-add-user "$user" "$SECRET_PASS" "$user@example.invalid" default > /dev/null
    done
    v-add-web-domain "$QI_USER" "$QI_DOMAIN" > /dev/null
}

# DokuWiki supports PHP 8.0 to 8.4 (v-add-web-php 8.4 on a server with 8.5 only)
setup() {
    if [ "$(v-list-quick-install-apps json | jq -r .DokuWiki.INSTALLABLE)" != yes ]; then
        skip 'DokuWiki needs one of PHP 8.0 to 8.4 on the server'
    fi
}

teardown_file() {
    for user in "$QI_USER" "$QI_OTHER"; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
}

@test "v-list-quick-install-apps lists the installers as JSON" {
    run v-list-quick-install-apps json
    assert_success
    run jq -r '.DokuWiki | "\(.NAME) \(.THUMBNAIL) \(.INSTALLABLE)"' <<< "$(v-list-quick-install-apps json)"
    assert_output 'DokuWiki dokuwiki-logo.svg yes'
    run jq -r '.WordPress.GROUP' <<< "$(v-list-quick-install-apps json)"
    assert_output 'cms'
}

@test "v-list-quick-install-app-options prints the form of a domain of the user" {
    run v-list-quick-install-app-options "$QI_USER" "$QI_DOMAIN" DokuWiki json
    assert_success
    json=$output
    run jq -r '.ROOT_CLEAN' <<< "$json"
    assert_output 'yes'
    run jq -r '.FIELDS[] | select(.KEY == "wiki_name") | "\(.TYPE) \(.REQUIRED) \(.LABEL)"' <<< "$json"
    assert_output 'text yes Wiki Name'
    run jq -r '.FIELDS[] | select(.KEY == "password") | .TYPE' <<< "$json"
    assert_output 'password'
    run jq -r '.FIELDS[] | select(.KEY == "php_version") | "\(.TYPE) \(.REQUIRED) \(.OPTIONS | length > 0)"' <<< "$json"
    assert_output 'select no true'
}

@test "v-list-quick-install-app-options refuses a domain of someone else and unknown apps" {
    run v-list-quick-install-app-options "$QI_OTHER" "$QI_DOMAIN" DokuWiki json
    assert_failure 3
    run v-list-quick-install-app-options "$QI_USER" "$QI_DOMAIN" NoSuchApp json
    assert_failure 3
    run v-list-quick-install-app-options "$QI_USER" "$QI_DOMAIN" '../x' json
    assert_failure
}

@test "v-start-user-quick-install refuses bad options files" {
    file=$(options_file '["not", "an", "object"]')
    run v-start-user-quick-install "$QI_USER" "$QI_DOMAIN" DokuWiki "$file"
    assert_failure 2
    run v-start-user-quick-install "$QI_USER" "$QI_DOMAIN" DokuWiki /nonexistent.json
    assert_failure 3
    run v-start-user-quick-install "$QI_OTHER" "$QI_DOMAIN" DokuWiki "$file"
    assert_failure 3
    rm -f "$file"
}

@test "A failed install is recorded and leaves no options behind" {
    # wiki_name is required
    file=$(options_file "{\"superuser\": \"admin\", \"password\": \"$SECRET_PASS\"}")
    run v-start-user-quick-install "$QI_USER" "$QI_DOMAIN" DokuWiki "$file"
    assert_success
    rm -f "$file"
    run wait_task "$QI_USER"
    assert_output 'failed'
    run v-list-user-quick-install-task "$QI_USER" 100 json
    assert_output --partial 'Missing required field: wiki_name'
    refute_output --partial "$SECRET_PASS"
    assert_file_not_exists "$ARCPANEL/data/users/$QI_USER/quick-install.options"
}

@test "DokuWiki installs in the background, one install at a time" {
    file=$(options_file "{\"wiki_name\": \"ArcWiki\", \"superuser\": \"admin\", \"real_name\": \"Admin\", \"email\": \"admin@example.invalid\", \"password\": \"$SECRET_PASS\"}")
    run v-start-user-quick-install "$QI_USER" "$QI_DOMAIN" DokuWiki "$file"
    assert_success
    assert_output --regexp '^[0-9]{14}$'
    # a second install of the user is refused while the first runs
    run v-start-user-quick-install "$QI_USER" "$QI_DOMAIN" DokuWiki "$file"
    assert_failure 7
    rm -f "$file"

    run wait_task "$QI_USER"
    assert_output 'done'
    run v-list-user-quick-install-task "$QI_USER" 100 json
    assert_output --partial 'DokuWiki installed successfully.'
    refute_output --partial "$SECRET_PASS"
    assert_file_not_exists "$ARCPANEL/data/users/$QI_USER/quick-install.options"
    run grep -c "$SECRET_PASS" "$ARCPANEL/log/system.log" "$ARCPANEL/data/users/$QI_USER/history.log"
    refute_output --regexp ':[1-9]'

    run curl -s --noproxy '*' --max-time 10 --resolve "$QI_DOMAIN:80:$(web_ip "$QI_USER" "$QI_DOMAIN")" "http://$QI_DOMAIN/doku.php"
    assert_output --partial 'ArcWiki'
    run v-list-user-log "$QI_USER" json
    assert_output --partial "Installed DokuWiki on $QI_DOMAIN."
}

@test "v-run-user-quick-install refuses a task it did not start" {
    run v-run-user-quick-install 1 "$QI_USER" "$QI_DOMAIN" DokuWiki
    assert_failure 10
}
