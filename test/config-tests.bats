#!/usr/bin/env bats

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

function random() {
head /dev/urandom | tr -dc 0-9 | head -c$1
}

function setup() {
    # echo "# Setup_file" > &3
    if [ $BATS_TEST_NUMBER = 1 ]; then
        echo 'user=test-5285' > /tmp/arcpanel-test-env.sh
        echo 'user2=test-5286' >> /tmp/arcpanel-test-env.sh
        echo 'userbk=testbk-5285' >> /tmp/arcpanel-test-env.sh
        echo 'userpass1=test-5285' >> /tmp/arcpanel-test-env.sh
        echo 'userpass2=t3st-p4ssw0rd' >> /tmp/arcpanel-test-env.sh
        echo 'ARCPANEL=/usr/local/arcpanel' >> /tmp/arcpanel-test-env.sh
        echo 'domain=test-5285.arcpanel.com' >> /tmp/arcpanel-test-env.sh
        echo 'domainuk=test-5285.arcpanel.com.uk' >> /tmp/arcpanel-test-env.sh
        echo 'rootdomain=testarcpanel.com' >> /tmp/arcpanel-test-env.sh
        echo 'subdomain=cdn.testarcpanel.com' >> /tmp/arcpanel-test-env.sh
        echo 'database=test-5285_database' >> /tmp/arcpanel-test-env.sh
        echo 'dbuser=test-5285_dbuser' >> /tmp/arcpanel-test-env.sh
    fi

    source /tmp/arcpanel-test-env.sh
    source $ARCPANEL/func/main.sh
    source $ARCPANEL/conf/arcpanel.conf
    source $ARCPANEL/func/ip.sh
}

@test "Setup Test domain" {
    run v-add-user $user $user $user@arcpanel.com default "Super Test"
    assert_success
    refute_output

    run v-add-web-domain $user 'testarcpanel.com'
    assert_success
    refute_output

    ssl=$(v-generate-ssl-cert "testarcpanel.com" "info@testarcpanel.com" US CA "Orange County" ArcPanel IT "mail.$domain" | tail -n1 | awk '{print $2}')
    mv $ssl/testarcpanel.com.crt /tmp/testarcpanel.com.crt
    mv $ssl/testarcpanel.com.key /tmp/testarcpanel.com.key

    # Use self signed certificates during last test
    run v-add-web-domain-ssl $user testarcpanel.com /tmp
    assert_success
    refute_output
}

@test "Web Config test" {
    for template in $(v-list-web-templates plain); do
        run v-change-web-domain-tpl $user testarcpanel.com $template
        assert_success
        refute_output
    done
}

@test "Proxy Config test" {
    if [ "$PROXY_SYSTEM" = "nginx" ]; then
        for template in $(v-list-proxy-templates plain); do
            run v-change-web-domain-proxy-tpl $user testarcpanel.com $template
            assert_success
            refute_output
        done
    else
        skip "Proxy not installed"
    fi
}

@test "Clean up" {
    run v-delete-user $user
    assert_success
    refute_output
}
