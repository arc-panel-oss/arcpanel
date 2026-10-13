#!/usr/bin/env bats
# The folders of a new user do not depend on the umask of the caller: a root
# shell with umask 077 (or a hardened server with 027) left /home/USER/web at
# 0700 root:USER, so the commands that run as the user could not create the
# folders of a web domain and the web server could not reach them.
# Run as root on a test server with a web system.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

USER_NAME=arcbatsumask
DOMAIN=arcbats-umask.example
PASS=Umask-Bats-Pass-1

setup_file() {
    v-delete-user "$USER_NAME" > /dev/null 2>&1 || true
    (umask 077 && v-add-user "$USER_NAME" "$PASS" "$USER_NAME@example.invalid" default > /dev/null)
}

teardown_file() {
    v-delete-user "$USER_NAME" > /dev/null 2>&1 || true
}

@test "v-add-user: web/ and conf/ get their modes under umask 077" {
    run stat -c '%a %U:%G' "/home/$USER_NAME/web"
    assert_output "751 root:$USER_NAME"
    # dovecot and the web server go through conf/ as their own users
    run bash -c "stat -c '%A' /home/$USER_NAME/conf | cut -c10"
    assert_output 'x'
}

@test "v-add-web-domain: a domain of that user is created without permission errors" {
    run bash -c "umask 077 && v-add-web-domain $USER_NAME $DOMAIN 2>&1 > /dev/null"
    assert_success
    refute_output --partial 'Permission denied'
    assert_file_exists "/home/$USER_NAME/web/$DOMAIN/public_html/index.html"
    run stat -c '%U:%G' "/home/$USER_NAME/web/$DOMAIN/public_html"
    assert_output "$USER_NAME:www-data"
}
