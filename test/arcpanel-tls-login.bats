#!/usr/bin/env bats
# Mail and FTP logins over encrypted connections only (E39 W5 L17,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md §12.2).
# Run as root on a server installed with the ArcPanel installer, with Dovecot
# and vsftpd or ProFTPD. Creates the user arctlsbats with the mail domain and
# web domain tls-bats.example; puts the mail and FTP settings back afterwards.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
USER_NAME=arctlsbats
DOMAIN=tls-bats.example
PASS="Tls-Bats-L17-Pass"
AUTH_CONF=/etc/dovecot/conf.d/10-auth.conf

conf_value() {
    grep -m1 "^$1=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2
}

# IMAP login of info@DOMAIN; FROM is the source address, the rest goes to curl.
# Dovecot treats a connection whose source and target address are the same as
# encrypted, so 127.0.0.2 -> 127.0.0.1 is a client from elsewhere.
imap_login() {
    local from=$1
    shift
    curl -s --max-time 20 --interface "$from" --url "imap://127.0.0.1:143/INBOX" \
        --user "info@$DOMAIN:$PASS" -X 'EXAMINE INBOX' "$@"
}

ftp_login() {
    curl -s --max-time 20 --url "ftp://127.0.0.1/" --user "${USER_NAME}_bats:$PASS" "$@"
}

setup_file() {
    v-delete-user "$USER_NAME" > /dev/null 2>&1 || true
    cp -p "$AUTH_CONF" "$BATS_FILE_TMPDIR/10-auth.conf" 2> /dev/null || true
    cp -p /etc/vsftpd.conf "$BATS_FILE_TMPDIR/vsftpd.conf" 2> /dev/null || true
    cp -p /etc/proftpd/tls.conf "$BATS_FILE_TMPDIR/proftpd-tls.conf" 2> /dev/null || true
    conf_value TLS_LOGIN_ONLY > "$BATS_FILE_TMPDIR/tls-login-only"
    v-add-user "$USER_NAME" "$PASS" "$USER_NAME@example.invalid" default > /dev/null
    v-add-mail-domain "$USER_NAME" "$DOMAIN" > /dev/null
    v-add-mail-account "$USER_NAME" "$DOMAIN" info "$PASS" > /dev/null
    v-add-web-domain "$USER_NAME" "$DOMAIN" > /dev/null
    v-add-web-domain-ftp "$USER_NAME" "$DOMAIN" bats "$PASS" > /dev/null
}

teardown_file() {
    local previous
    v-delete-user "$USER_NAME" > /dev/null 2>&1 || true
    [ -f "$BATS_FILE_TMPDIR/10-auth.conf" ] && cp -p "$BATS_FILE_TMPDIR/10-auth.conf" "$AUTH_CONF"
    [ -f "$BATS_FILE_TMPDIR/vsftpd.conf" ] && cp -p "$BATS_FILE_TMPDIR/vsftpd.conf" /etc/vsftpd.conf
    [ -f "$BATS_FILE_TMPDIR/proftpd-tls.conf" ] && cp -p "$BATS_FILE_TMPDIR/proftpd-tls.conf" /etc/proftpd/tls.conf
    previous=$(cat "$BATS_FILE_TMPDIR/tls-login-only")
    if [ -n "$previous" ]; then
        v-change-sys-config-value TLS_LOGIN_ONLY "$previous" > /dev/null
    else
        sed -i "/^TLS_LOGIN_ONLY=/d" "$ARCPANEL/conf/arcpanel.conf"
    fi
    systemctl try-restart dovecot vsftpd proftpd > /dev/null 2>&1 || true
}

@test "tls login: arguments are checked and demo mode refuses the change" {
    run v-change-sys-tls-login
    assert_failure 1
    run v-change-sys-tls-login maybe
    assert_failure 2
    v-change-sys-config-value DEMO_MODE yes
    run v-change-sys-tls-login yes
    sed -i "s/^DEMO_MODE='yes'/DEMO_MODE='no'/" "$ARCPANEL/conf/arcpanel.conf"
    assert_failure 1
    assert_output --partial 'security restrictions'
}

@test "tls login: without the switch, plain-text IMAP and FTP logins work" {
    run v-change-sys-tls-login no
    assert_success
    run imap_login 127.0.0.2
    assert_success
    if [ "$(conf_value FTP_SYSTEM)" = vsftpd ] || [ "$(conf_value FTP_SYSTEM)" = proftpd ]; then
        run ftp_login
        assert_success
    fi
}

@test "tls login: yes writes the service settings and the key, and lists the key" {
    run v-change-sys-tls-login yes
    assert_success
    if [[ $(dovecot --version) == 2.3* ]]; then
        run grep -c '^disable_plaintext_auth = yes$' "$AUTH_CONF"
        assert_output 1
    else
        run grep -c '^auth_allow_cleartext = no$' "$AUTH_CONF"
        assert_output 1
    fi
    case "$(conf_value FTP_SYSTEM)" in
        vsftpd)
            run grep -cE '^(force_local_logins_ssl=YES|pasv_promiscuous=NO)$' /etc/vsftpd.conf
            assert_output 2
            ;;
        proftpd)
            run grep -cE '^TLSRequired +auth$' /etc/proftpd/tls.conf
            assert_output 1
            ;;
    esac
    run v-list-sys-config json
    assert_equal "$(jq -r .config.TLS_LOGIN_ONLY <<< "$output")" yes
    run v-list-sys-config plain
    assert_output --partial 'TLS-only mail and FTP login:      yes'
}

@test "tls login: plain-text IMAP from another address is refused; STARTTLS and webmail on the server sign in" {
    v-change-sys-tls-login yes > /dev/null
    run imap_login 127.0.0.2
    assert_failure
    run imap_login 127.0.0.2 --ssl-reqd --insecure
    assert_success
    # Roundcube and SnappyMail connect to localhost:143 without TLS
    run imap_login 127.0.0.1
    assert_success
}

@test "tls login: plain-text FTP login is refused; explicit TLS signs in" {
    case "$(conf_value FTP_SYSTEM)" in
        vsftpd | proftpd) ;;
        *) skip "no FTP server" ;;
    esac
    v-change-sys-tls-login yes > /dev/null
    run ftp_login
    assert_failure
    run ftp_login --ssl-reqd --insecure
    assert_success
}

@test "tls login: no allows plain text again and keeps passive connections from the client only" {
    v-change-sys-tls-login yes > /dev/null
    run v-change-sys-tls-login no
    assert_success
    if [[ $(dovecot --version) == 2.3* ]]; then
        run grep -c '^disable_plaintext_auth = no$' "$AUTH_CONF"
        assert_output 1
        run imap_login 127.0.0.2
        assert_success
    fi
    if [ "$(conf_value FTP_SYSTEM)" = vsftpd ]; then
        run grep -cE '^(force_local_logins_ssl=NO|pasv_promiscuous=NO)$' /etc/vsftpd.conf
        assert_output 2
        run ftp_login
        assert_success
    fi
    assert_equal "$(conf_value TLS_LOGIN_ONLY)" no
}
