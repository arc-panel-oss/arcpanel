#!/usr/bin/env bats
# ArcPanel webmail single sign-on, back end (E46,
# docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md §7).
# Run as root on a server installed with the ArcPanel installer, with Exim,
# Dovecot and Roundcube. Creates the user arcssobats with mail domains
# sso-bats.example and sso-bats2.example.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
USER_NAME=arcssobats
DOMAIN=sso-bats.example
DOMAIN2=sso-bats2.example
PASSDB=/etc/dovecot/arcpanel-sso.passwd
TICKETS=$ARCPANEL/data/mail-sso
PASS="Sso-Bats-E46-Pass"

server_ip() {
    ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | head -n1
}

# A ticket in a root-owned file under /tmp, as the panel passes it
ticket_file() {
    local file
    file=$(mktemp /tmp/sso-bats.XXXXXX)
    printf '%s\n' "$1" > "$file"
    echo "$file"
}

# Ticket, exchanged for CLIENT_IP; prints the JSON answer
exchange() {
    local file
    file=$(ticket_file "$1")
    v-check-mail-account-sso "$file" "$2" json
    local rc=$?
    rm -f "$file"
    return $rc
}

# Sign on ACCOUNT@DOMAIN from 203.0.113.9 and print the session password
session_password() {
    local ticket
    ticket=$(v-add-mail-account-sso "$USER_NAME" "${2:-$DOMAIN}" "$1" admin 203.0.113.9)
    exchange "$ticket" 203.0.113.9 | jq -r .PASSWORD
}

passdb_lines() {
    grep -c "^$1:" "$PASSDB" 2> /dev/null || true
}

cleanup() {
    v-delete-user "$USER_NAME" > /dev/null 2>&1 || true
    sed -i "/@sso-bats\(2\)\?\.example:/d" "$PASSDB" 2> /dev/null || true
    rm -f /tmp/sso-bats.*
}

setup_file() {
    cleanup
    v-add-user "$USER_NAME" "$PASS" "$USER_NAME@example.invalid" default > /dev/null
    for domain in "$DOMAIN" "$DOMAIN2"; do
        v-add-mail-domain "$USER_NAME" "$domain" > /dev/null
        v-add-mail-domain-webmail "$USER_NAME" "$domain" roundcube no > /dev/null 2>&1 || true
    done
    v-add-mail-account "$USER_NAME" "$DOMAIN" info "$PASS" > /dev/null
    v-add-mail-account "$USER_NAME" "$DOMAIN" sales "$PASS" > /dev/null
    v-add-mail-account "$USER_NAME" "$DOMAIN2" info "$PASS" > /dev/null
}

teardown_file() {
    cleanup
}

@test "setup: Dovecot passdb, empty passdb file, cron job, once" {
    run v-add-sys-mail-sso
    assert_success
    run v-add-sys-mail-sso
    assert_success
    run grep -c '^!include auth-arcpanel-sso.conf.ext' /etc/dovecot/conf.d/10-auth.conf
    assert_output 1
    assert_file_exist /etc/dovecot/conf.d/auth-arcpanel-sso.conf.ext
    run stat -c '%U:%G %a' "$PASSDB"
    assert_output 'root:dovecot 640'
    assert_file_exist /etc/cron.d/arcpanel-mail-sso
    run doveconf -n
    assert_output --partial 'arcpanel-sso.passwd'
}

@test "ticket: printed once, stored by its hash, written to the user log" {
    run v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 203.0.113.9
    assert_success
    assert_output --regexp '^[A-Za-z0-9_-]{43}$'
    hash=$(printf '%s' "$output" | sha256sum | cut -d' ' -f1)
    assert_file_exist "$TICKETS/$hash"
    run stat -c '%a' "$TICKETS/$hash"
    assert_output 600
    run grep -c "$output" "$TICKETS/$hash"
    assert_output 0
    run v-list-user-log "$USER_NAME" json
    assert_output --partial "Webmail sign-on (Account: info@$DOMAIN, By: admin)"
}

@test "ticket: refused for a suspended or unknown account, a bad address or another webmail" {
    run v-add-mail-account-sso "$USER_NAME" "$DOMAIN" nobody admin 203.0.113.9
    assert_failure
    run v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 'not-an-ip'
    assert_failure
    v-suspend-mail-account "$USER_NAME" "$DOMAIN" sales
    run v-add-mail-account-sso "$USER_NAME" "$DOMAIN" sales admin 203.0.113.9
    assert_failure
    v-unsuspend-mail-account "$USER_NAME" "$DOMAIN" sales
    v-delete-mail-domain-webmail "$USER_NAME" "$DOMAIN2" no > /dev/null 2>&1
    run v-add-mail-account-sso "$USER_NAME" "$DOMAIN2" info admin 203.0.113.9
    assert_failure
    v-add-mail-domain-webmail "$USER_NAME" "$DOMAIN2" roundcube no > /dev/null 2>&1
}

@test "exchange: a session password for the ticket's address, once" {
    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 203.0.113.9)
    run exchange "$ticket" 203.0.113.9
    assert_success
    assert_equal "$(jq -r .USER <<< "$output")" "info@$DOMAIN"
    [[ "$(jq -r .PASSWORD <<< "$output")" =~ ^[A-Za-z0-9_-]{43}$ ]]
    run exchange "$ticket" 203.0.113.9
    assert_failure
}

@test "exchange: refused for another address, an expired ticket or a ticket not in a file" {
    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 203.0.113.9)
    run exchange "$ticket" 203.0.113.10
    assert_failure
    # The refused ticket was used up too
    run exchange "$ticket" 203.0.113.9
    assert_failure

    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 203.0.113.9)
    hash=$(printf '%s' "$ticket" | sha256sum | cut -d' ' -f1)
    sed -i "s/EXPIRES='[0-9]*'/EXPIRES='1'/" "$TICKETS/$hash"
    run exchange "$ticket" 203.0.113.9
    assert_failure

    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 203.0.113.9)
    run v-check-mail-account-sso "$ticket" 203.0.113.9 json
    assert_failure
    refute_output --partial PASSWORD
}

@test "exchange: refused when the account was suspended since" {
    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" sales admin 203.0.113.9)
    v-suspend-mail-account "$USER_NAME" "$DOMAIN" sales
    run exchange "$ticket" 203.0.113.9
    assert_failure
    v-unsuspend-mail-account "$USER_NAME" "$DOMAIN" sales
}

@test "passdb: one loopback-only line per address, replaced by a new sign-on" {
    session_password info > /dev/null
    session_password info > /dev/null
    run passdb_lines "info@$DOMAIN"
    assert_output 1
    run grep "^info@$DOMAIN:" "$PASSDB"
    assert_output --regexp "^info@$DOMAIN:\{SHA512-CRYPT\}\\\$6\\\$[^:]+:::[0-9]+:::allow_nets=127.0.0.1/32,::1/128$"
}

@test "Dovecot: the session password works from the loopback address only, the account password still works" {
    password=$(session_password info)
    run doveadm auth test -x rip=127.0.0.1 "info@$DOMAIN" "$password"
    assert_success
    run doveadm auth test -x rip="$(server_ip)" "info@$DOMAIN" "$password"
    assert_failure
    run doveadm auth test -x rip="$(server_ip)" "info@$DOMAIN" "$PASS"
    assert_success
    # Not for another account of the domain
    run doveadm auth test -x rip=127.0.0.1 "sales@$DOMAIN" "$password"
    assert_failure
}

@test "IMAP and SMTP: Roundcube's connections log in with the session password" {
    password=$(session_password info)
    run curl -s --max-time 20 --url "imap://127.0.0.1:143/INBOX" --user "info@$DOMAIN:$password" -X 'EXAMINE INBOX'
    assert_success
    printf 'Subject: E46 sign-on\r\n\r\nhello\r\n' > /tmp/sso-bats.mail
    run curl -s --max-time 30 --url "smtp://127.0.0.1:587" --ssl-reqd --insecure --user "info@$DOMAIN:$password" \
        --mail-from "info@$DOMAIN" --mail-rcpt "sales@$DOMAIN" --upload-file /tmp/sso-bats.mail
    assert_success
    run curl -s --max-time 30 --url "smtp://$(server_ip):587" --ssl-reqd --insecure --user "info@$DOMAIN:$password" \
        --mail-from "info@$DOMAIN" --mail-rcpt "sales@$DOMAIN" --upload-file /tmp/sso-bats.mail
    assert_failure
    # The account password from the same address: the refusal above is allow_nets
    run curl -s --max-time 30 --url "smtp://$(server_ip):587" --ssl-reqd --insecure --user "info@$DOMAIN:$PASS" \
        --mail-from "info@$DOMAIN" --mail-rcpt "sales@$DOMAIN" --upload-file /tmp/sso-bats.mail
    assert_success
}

@test "revocation: password change, suspension and deletion end the sessions" {
    session_password info > /dev/null
    v-change-mail-account-password "$USER_NAME" "$DOMAIN" info "$PASS-2"
    run passdb_lines "info@$DOMAIN"
    assert_output 0

    session_password sales > /dev/null
    v-suspend-mail-account "$USER_NAME" "$DOMAIN" sales
    run passdb_lines "sales@$DOMAIN"
    assert_output 0
    v-unsuspend-mail-account "$USER_NAME" "$DOMAIN" sales

    session_password info > /dev/null
    session_password sales > /dev/null
    session_password info "$DOMAIN2" > /dev/null
    v-suspend-mail-domain "$USER_NAME" "$DOMAIN"
    run passdb_lines "info@$DOMAIN"
    assert_output 0
    run passdb_lines "sales@$DOMAIN"
    assert_output 0
    # Only that domain
    run passdb_lines "info@$DOMAIN2"
    assert_output 1
    v-unsuspend-mail-domain "$USER_NAME" "$DOMAIN"

    v-delete-mail-domain-webmail "$USER_NAME" "$DOMAIN2" no > /dev/null 2>&1
    run passdb_lines "info@$DOMAIN2"
    assert_output 0
    v-add-mail-domain-webmail "$USER_NAME" "$DOMAIN2" roundcube no > /dev/null 2>&1
}

@test "prune: expired sessions and tickets go, current ones stay" {
    session_password info > /dev/null
    session_password sales > /dev/null
    sed -i "s/^\(info@$DOMAIN:[^:]*:::\)[0-9]*:/\11:/" "$PASSDB"
    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 203.0.113.9)
    hash=$(printf '%s' "$ticket" | sha256sum | cut -d' ' -f1)
    sed -i "s/EXPIRES='[0-9]*'/EXPIRES='1'/" "$TICKETS/$hash"
    run v-update-mail-sso
    assert_success
    run passdb_lines "info@$DOMAIN"
    assert_output 0
    run passdb_lines "sales@$DOMAIN"
    assert_output 1
    assert_file_not_exist "$TICKETS/$hash"
    run stat -c '%U:%G %a' "$PASSDB"
    assert_output 'root:dovecot 640'
}

@test "Roundcube: a ticket posted to the webmail port signs in, once" {
    v-add-sys-mail-sso
    assert_file_exist /var/lib/roundcube/plugins/arcpanel_sso/arcpanel_sso.php
    run grep -c 'arcpanel_sso' /etc/roundcube/config.inc.php
    refute_output 0
    port=$(grep -m1 "^WEBMAIL_PORT=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
    [ -n "$port" ]
    jar=$(mktemp /tmp/sso-bats.XXXXXX)
    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 127.0.0.1)
    run curl -sk -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' \
        --data-urlencode "_ticket=$ticket" "https://127.0.0.1:$port/?_task=login&_action=arcpanel-sso"
    assert_output --regexp '^302 .*_task=mail'
    run curl -sk -c "$jar" -b "$jar" "https://127.0.0.1:$port/?_task=mail&_mbox=INBOX"
    assert_output --partial "info@$DOMAIN"

    # The same ticket again: the login page, no session
    rm -f "$jar"
    run curl -sk -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' \
        --data-urlencode "_ticket=$ticket" "https://127.0.0.1:$port/?_task=login&_action=arcpanel-sso"
    refute_output 302
    rm -f "$jar"
}

@test "panel: the ticket exchange answers only on the loopback address" {
    ticket=$(v-add-mail-account-sso "$USER_NAME" "$DOMAIN" info admin 127.0.0.1)
    backend=$(grep -m1 "^BACKEND_PORT=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
    run curl -sk -o /dev/null -w '%{http_code}' --data "ticket=$ticket&client_ip=127.0.0.1" "https://$(server_ip):${backend:-8083}/sso/mail"
    assert_output 404
    run curl -sk -o /dev/null -w '%{http_code}' -H 'X-Forwarded-For: 127.0.0.1' --data "ticket=$ticket&client_ip=127.0.0.1" "https://127.0.0.1:${backend:-8083}/sso/mail"
    assert_output 404
    run curl -sk --data "ticket=$ticket&client_ip=127.0.0.1" "https://127.0.0.1:${backend:-8083}/sso/mail"
    assert_output --partial "\"user\":\"info@$DOMAIN\""
}
