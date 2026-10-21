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

@test "is_hash_format_valid accesskey:secret valid" {
    run is_hash_format_valid 'bxDaKPyAfLPRgSkoqlkI:Pc8czGPRECp3GxTNMr3LF6zWc8cjfPrNHy_-=A' "hash"
    assert_success
}
@test "is_access_key_id_format_valid valid" {
    run is_access_key_id_format_valid 'M0ocDoIKbsoXSqtk1mgc' "key"
    assert_success
}
@test "is_access_key_id_format_valid short" {
    run is_access_key_id_format_valid 'M0ocDoIKbsoXSqtk1mg' "key"
    assert_failure $E_INVALID
}
@test "is_access_key_id_format_valid long" {
    run is_access_key_id_format_valid 'M0ocDoIKbsoXSqtk1mgca' "key"
    assert_failure $E_INVALID
}
@test "is_access_key_id_format_valid non alpha" {
    run is_access_key_id_format_valid 'M0ocDoIKbsoX$qtk1mgc' "key"
    assert_failure $E_INVALID
}

@test "is_access_key_id_format_valid LHF" {
    run is_access_key_id_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_user_format_valid valid" {
    run is_user_format_valid 'hxh54SKbALne4s69VsqJRMbMd8Br' "key"
    assert_success
}
@test "is_user_format_valid short" {
    run is_user_format_valid 'hxh54SKbALne4s69VsqJR' "key"
    assert_success
}
@test "is_user_format_valid long" {
    run is_user_format_valid 'hxh54SKbALne4s69VsqJRMbMd8Braaa' "key"
    assert_failure $E_INVALID
}
@test "is_user_format_valid dash" {
    run is_user_format_valid 'hxh54SKbALne4-s6-VsqJRMbMd8Br' "key"
    assert_success
}

@test "is_user_format_valid dash repeat" {
    run is_user_format_valid 'hxh54SKbALne4s6--VsqJRMbMd8Br' "key"
    assert_success
}
@test "is_user_format_valid dash start" {
    run is_user_format_valid '-hxh54SKbALne4s6VsqJRMbMd8Br' "key"
    assert_failure $E_INVALID
}
@test "is_user_format_valid dash end" {
    run is_user_format_valid 'hxh54SKbALne4s6VsqJRMbMd8Br-' "key"
    assert_failure $E_INVALID
}
@test "is_user_format_valid LHF" {
    run is_user_format_valid 'M0ocDoIK
    soXSqtk1mgc' "key"
    assert_failure $E_INVALID
}

@test "is_fw_action_format_valid ACCEPT" {
    run is_fw_action_format_valid 'ACCEPT' "key"
    assert_success
}
@test "is_fw_action_format_valid DROP" {
    run is_fw_action_format_valid 'DROP' "key"
    assert_success
}
@test "is_fw_action_format_valid TEST" {
    run is_fw_action_format_valid 'TEST' "key"
    assert_failure $E_INVALID
}
@test "is_fw_action_format_valid LHF" {
    run is_fw_protocol_format_valid 'M0ocDoIK
    soXSqtk1mgc' "key"
    assert_failure $E_INVALID
}

@test "is_fw_protocol_format_valid ICMP" {
    run is_fw_protocol_format_valid 'ICMP' "key"
    assert_success
}

@test "is_fw_protocol_format_valid UDP" {
    run is_fw_protocol_format_valid 'UDP' "key"
    assert_success
}
@test "is_fw_protocol_format_valid TCP" {
    run is_fw_protocol_format_valid 'TCP' "key"
    assert_success
}
@test "is_fw_protocol_format_valid TEST" {
    run is_fw_protocol_format_valid 'TEST' "key"
    assert_failure $E_INVALID
}


@test "is_domain_format_valid success" {
     run is_domain_format_valid 'arcpanel.com' "key"
    assert_success
}

@test "is_domain_format_valid www" {
     run is_domain_format_valid 'www' "key"
    assert_failure $E_INVALID
}
@test "is_domain_format_valid number" {
     run is_domain_format_valid '12345' "key"
    assert_failure $E_INVALID
}

@test "is_domain_format_valid .." {
     run is_domain_format_valid '..' "key"
    assert_failure $E_INVALID
}

@test "is_domain_format_valid arcpanel.com." {
     run is_domain_format_valid 'mx.arcpanel.com.' "key"
    assert_success
}

@test "is_domain_format_valid LF." {
     run is_domain_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_dns_record_format_valid" {
	rtype='MX'
	priority=1;
	run is_dns_record_format_valid 'mx.arcpanel.com.'
	assert_success
}

@test "is_dns_record_format_valid MX missing priority" {
	rtype='MX'
	priority=''
	run is_dns_record_format_valid 'mx.arcpanel.com.'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid SRV 4-field" {
	rtype='SRV'
	priority=''
	run is_dns_record_format_valid '10 20 5060 srv.arcpanel.com.'
	assert_success
}

@test "is_dns_record_format_valid SRV invalid priority" {
	rtype='SRV'
	priority=''
	run is_dns_record_format_valid 'abc 20 5060 srv.arcpanel.com.'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid SRV null target" {
	rtype='SRV'
	priority=''
	run is_dns_record_format_valid '0 5 0 .'
	assert_success
}

@test "is_dns_record_format_valid TXT newline" {
	rtype='TXT'
	priority=''
	run is_dns_record_format_valid 'foo
bar'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid TXT single quote" {
	rtype='TXT'
	priority=''
	run is_dns_record_format_valid "foo'bar"
	assert_success
}

@test "is_dns_record_format_valid TXT empty" {
	rtype='TXT'
	priority=''
	run is_dns_record_format_valid ''
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid TXT non-ascii" {
	rtype='TXT'
	priority=''
	run is_dns_record_format_valid 'café'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid DNSKEY valid" {
	rtype='DNSKEY'
	priority=''
	run is_dns_record_format_valid '257 3 13 AwEAAc1='
	assert_success
}

@test "is_dns_record_format_valid DNSKEY invalid protocol" {
	rtype='DNSKEY'
	priority=''
	run is_dns_record_format_valid '257 1 13 AwEAAc1'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid DS invalid hex" {
	rtype='DS'
	priority=''
	run is_dns_record_format_valid '12345 8 1 ZZZZ'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid TLSA valid" {
	rtype='TLSA'
	priority=''
	run is_dns_record_format_valid '3 1 1 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
	assert_success
}

@test "is_dns_record_format_valid CAA missing value" {
	rtype='CAA'
	priority=''
	run is_dns_record_format_valid '0 issue'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid IPSECKEY valid" {
	rtype='IPSECKEY'
	priority=''
	run is_dns_record_format_valid '10 1 2 192.0.2.1 AQIDBA=='
	assert_success
}

@test "is_dns_record_format_valid IPSECKEY invalid gateway" {
	rtype='IPSECKEY'
	priority=''
	run is_dns_record_format_valid '10 1 2 not-an-ip AQIDBA=='
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid IPSECKEY algorithm0 with key fails" {
	rtype='IPSECKEY'
	priority=''
	run is_dns_record_format_valid '10 1 0 192.0.2.1 AQIDBA=='
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid TLSA type1 wrong length" {
	rtype='TLSA'
	priority=''
	run is_dns_record_format_valid '3 1 1 0123'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid TLSA matching_type plus sign wrong length" {
	rtype='TLSA'
	priority=''
	run is_dns_record_format_valid '3 1 +1 0123'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid DS type1 wrong length" {
	rtype='DS'
	priority=''
	run is_dns_record_format_valid '12345 8 1 012345'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid DS type2 correct length" {
	rtype='DS'
	priority=''
	run is_dns_record_format_valid '12345 8 2 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
	assert_success
}

@test "is_dns_record_format_valid KEY algorithm0 empty key" {
	rtype='KEY'
	priority=''
	run is_dns_record_format_valid '256 3 0'
	assert_success
}

@test "is_dns_record_format_valid KEY algorithm0 with key fails" {
	rtype='KEY'
	priority=''
	run is_dns_record_format_valid '256 3 0 AQID'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid KEY algorithm1 missing key fails" {
	rtype='KEY'
	priority=''
	run is_dns_record_format_valid '256 3 1'
	assert_failure $E_INVALID
}

@test "is_dns_record_format_valid test" {
	rtype='MX'
	priority=1;
	run is_dns_record_format_valid 'c
1eshutdown
r'
	assert_failure $E_INVALID
}

@test "is_alias_format_valid success" {
     run is_alias_format_valid 'arcpanel.com' "key"
    assert_success
}

@test "is_alias_format_valid success www.domain.com" {
     run is_alias_format_valid 'www.arcpanel.com' "key"
    assert_success
}
@test "is_alias_format_valid success arcpanel.com,www.arcpanel.com" {
     run is_alias_format_valid 'arcpanel.com,www.arcpanel.com' "key"
    assert_success
}

@test "is_alias_format_valid success *.arcpanel.com" {
     run is_alias_format_valid '*.arcpanel.com' "key"
    assert_success
}

@test "is_alias_format_valid success www.arcpanel.com,*.arcpanel.com" {
     run is_alias_format_valid 'www.arcpanel.com,*.arcpanel.com' "key"
    assert_success
}

@test "is_extention_format_valid test" {
     run is_extention_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_string_format_valid test" {
     run is_string_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_database_format_valid test" {
     run is_database_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_date_format_valid test" {
     run is_date_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_dbuser_format_valid test" {
     run is_dbuser_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_dns_type_format_valid test" {
     run is_dns_type_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_email_format_valid test" {
     run is_email_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_fw_port_format_valid test" {
     run is_fw_port_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_int_format_valid test" {
     run is_int_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_interface_format_valid test" {
     run is_interface_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_ip_status_format_valid test" {
     run is_ip_status_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_cron_format_valid test" {
     run is_cron_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_name_format_valid test" {
     run is_name_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}


@test "is_role_valid test" {
     run is_role_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_object_format_valid test" {
     run is_object_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}


@test "is_common_format_valid test" {
     run is_common_format_valid 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "format_no_quotes test2" {
     run format_no_quotes 'test bericht' "key"
    assert_success
}

@test "format_no_quotes .." {
     run format_no_quotes '..' "key"
    assert_success
}

@test "format_no_quotes text." {
     run format_no_quotes 'text.' "key"
    assert_success
}

@test "is_common_format_valid text" {
     run is_common_format_valid 'text' "key"
    assert_success
}


@test "format_no_quotes test" {
     run format_no_quotes 'c
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_type_valid" {
    run is_type_valid 'c
    1eshutdown
    r' "test,key"
    assert_failure $E_INVALID
}

@test "is_command_valid_format v-list-users" {
    run is_command_valid_format 'v-list-users'
    assert_success
}

@test "is_command_valid_format v-list--users (Fail)" {
    run is_command_valid_format 'v-list--users'
    assert_failure $E_INVALID
}

@test "is_command_valid_format h-list-users (Fail)" {
    run is_command_valid_format 'h-list-users'
    assert_failure $E_INVALID
}

@test "is_command_valid_format list-users (Fail)" {
    run is_command_valid_format 'list-users'
    assert_failure $E_INVALID
}

@test "is_command_valid_format vlist-users (Fail)" {
    run is_command_valid_format 'vlist-users'
    assert_failure $E_INVALID
}

@test "is_command_valid_format LF (Fail)" {
     run is_command_valid_format 'v-
1eshutdown
r' "key"
    assert_failure $E_INVALID
}

@test "is_comment_format_valid with spaces, dots, dashes, underscores (Pass)" {
    run is_comment_format_valid 'deploy key-2.prod_eu' "comment"
    assert_success
}

@test "is_comment_format_valid control characters (Fail)" {
    local c
    for c in $'\n' $'\r' $'\t' $'\v' $'\f'; do
        run is_comment_format_valid "ab${c}USER admin" "comment"
        assert_failure $E_INVALID
    done
}

# Cron fields (E39 W5 §6.1); the same cases as web-app/tests/Unit/CronFieldTest.php

@test "is_cron_format_valid builder, import and padded values (Pass)" {
    local pair
    for pair in 'min *' 'min */5' 'min */30' 'min */59' 'min 0' 'min 00' 'min 05' 'min 0-59/15' 'min 1,15,30,45' \
        'hour */12' 'hour 23' 'hour 0-23/2' 'day 1-31/2' 'day */15' 'day 31' 'month 1-11/2' 'month */6' 'month 12' \
        'wday 1,2,3,4,5' 'wday 0,6' 'wday 7' 'wday 1-5'; do
        run is_cron_format_valid "${pair#* }" "${pair%% *}"
        assert_success
    done
}

@test "is_cron_format_valid out of range, names, macros, quotes, control characters (Fail)" {
    local pair
    for pair in 'min 60' 'min */60' 'min */0' 'min 5/15' 'min 1,' 'min ,1' 'min 1,,2' 'min 30-10' 'min 100' 'min -1' \
        'min *-5' 'min 1|2' "min 5'" 'min 5"' 'hour 24' 'day 0' 'day 32' 'month 0' 'month 13' 'month jan' \
        'wday 8' 'wday mon' 'min @daily'; do
        run is_cron_format_valid "${pair#* }" "${pair%% *}"
        assert_failure $E_INVALID
    done
    for value in '' '5 * * * * evil' $'5\nevil' $'5\rx' $'5\tx' "$(printf '1,%.0s' $(seq 1 64))1"; do
        run is_cron_format_valid "$value" "min"
        assert_failure $E_INVALID
    done
}

# Validators of E39 W5 §6.2

@test "Names, objects, services, hashes and commands refuse a pipe (Fail)" {
    run is_user_format_valid 'ab|cd' "user"
    assert_failure $E_INVALID
    run is_object_format_valid 'ab|cd' "object"
    assert_failure $E_INVALID
    run is_object_name_format_valid 'ab|cd' "object"
    assert_failure $E_INVALID
    run is_object_name_format_valid '' "object"
    assert_failure $E_INVALID
    run is_service_format_valid 'nginx|x' "service"
    assert_failure $E_INVALID
    run is_hash_format_valid 'abc|def' "hash"
    assert_failure $E_INVALID
    run is_command_valid_format 'v-list|users' "command"
    assert_failure $E_INVALID
    run is_secret_access_key_format_valid "$(printf 'a%.0s' $(seq 1 39))|" "secret"
    assert_failure $E_INVALID
}

@test "Names, objects, services and commands keep dots, dashes and underscores (Pass)" {
    run is_user_format_valid 'ab.c-d_e' "user"
    assert_success
    run is_object_format_valid 'pkg.small-1_x' "object"
    assert_success
    run is_object_name_format_valid 'my object.1' "object"
    assert_success
    run is_service_format_valid 'php8.3-fpm' "service"
    assert_success
    run is_command_valid_format 'v-list-users' "command"
    assert_success
}

@test "Common values refuse tab, carriage return and other control characters (Fail)" {
    local c
    for c in $'\t' $'\r' $'\e' $'\x01'; do
        run is_common_format_valid "ab${c}cd" "value"
        assert_failure $E_INVALID
        run is_string_format_valid "ab${c}cd" "value"
        assert_failure $E_INVALID
        run is_database_format_valid "ab${c}cd" "database"
        assert_failure $E_INVALID
        run is_dbuser_format_valid "ab${c}cd" "dbuser"
        assert_failure $E_INVALID
        run is_domain_format_valid "ab${c}cd.example" "domain"
        assert_failure $E_INVALID
        run is_common_format_spaces_valid "ab${c}cd" "value"
        assert_failure $E_INVALID
    done
}

@test "is_no_new_line_format accepts -n and -e and refuses CR (Pass, Fail)" {
    run is_no_new_line_format '-n'
    assert_success
    run is_no_new_line_format '-e'
    assert_success
    run is_no_new_line_format $'ab\rcd'
    assert_failure $E_INVALID
}

@test "is_name_format_valid refuses dollar, backtick, backslash and control characters (Fail)" {
    local v
    for v in 'Jane $HOME' 'Jane `id`' 'Jane\Doe' $'Jane\tDoe' $'Jane\rDoe'; do
        run is_name_format_valid "$v" "name"
        assert_failure $E_INVALID
    done
    run is_name_format_valid 'Jane Doe-Smith' "name"
    assert_success
}

@test "is_alias_format_valid splits on commas without control characters (Pass, Fail)" {
    run is_alias_format_valid 'a.example,b.example' "alias"
    assert_success
    run is_alias_format_valid 'a.example, b.example' "alias"
    assert_success
    run is_alias_format_valid $'a.example\nb.example' "alias"
    assert_failure $E_INVALID
    run is_alias_format_valid $'a.example\tb.example' "alias"
    assert_failure $E_INVALID
}

@test "Ids, IP status and system types match exactly (Pass, Fail)" {
    run is_id_format_valid '5' "id"
    assert_success
    run is_id_format_valid $'5\nfoo' "id"
    assert_failure $E_INVALID
    run is_ip_status_format_valid 'shared' "status"
    assert_success
    run is_ip_status_format_valid 'shared,dedicated' "status"
    assert_failure $E_INVALID
    run is_type_valid 'plain json shell csv' 'json'
    assert_success
    run is_type_valid 'plain json shell csv' 'js'
    assert_failure $E_INVALID
    run is_type_valid 'nginx,apache2' 'apache'
    assert_failure $E_INVALID
    run is_type_valid 'sftp,ftp,b2,rclone' 'b2'
    assert_success
}

@test "is_format_valid_shell matches a shell of /etc/shells exactly (Pass, Fail)" {
    run is_format_valid_shell 'bash'
    assert_success
    run is_format_valid_shell '/bin/bash'
    assert_success
    run is_format_valid_shell 'ba'
    assert_failure $E_INVALID
    run is_format_valid_shell 'bash x'
    assert_failure $E_INVALID
}

@test "is_cron_command_valid_format carriage return (Fail)" {
    run is_cron_command_valid_format $'echo hi\r' "command"
    assert_failure $E_INVALID
}
