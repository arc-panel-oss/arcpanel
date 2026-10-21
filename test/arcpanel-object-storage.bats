#!/usr/bin/env bats
# E44: object storage on Silo
# docs/features/specs/2026-10-19-003-e44-object-storage-silo-design.md §14.
# Run as root on a test server with Internet access (the pinned packages
# are downloaded once). Silo stays installed afterwards; the test accounts,
# their buckets and keys are removed. S3 clients reach Silo through the
# endpoint domain over plain HTTP (no Let's Encrypt on a test server).

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
U1=arcbatsos1
U2=arcbatsos2
U3=arcbatsos3
RES=arcbatsosres
PKG=arcbats-os
PKG_RES=arcbats-os-res
PKG_NONE=arcbats-os-none
B1=arcbats-media
B2=arcbats-files
ENDPOINT_HOST=s3.arcbats.test
STATE="$BATS_FILE_TMPDIR/state"

conf_value() {
    sed -n "s/^$1='\([^']*\)'.*/\1/p" "$ARCPANEL/conf/arcpanel.conf" | tail -n 1
}

mcli_root() {
    (
        source "$ARCPANEL/func/main.sh" > /dev/null 2>&1
        source "$ARCPANEL/func/silo.sh"
        silo_mcli "$@"
    )
}

# Save a key's ID and secret from v-add-user-s3-key json under NAME
save_key() {
    jq -r .KEY <<< "$2" > "$BATS_FILE_TMPDIR/$1.id"
    jq -r .SECRET <<< "$2" > "$BATS_FILE_TMPDIR/$1.secret"
}

key_id() {
    cat "$BATS_FILE_TMPDIR/$1.id"
}

# aws through the endpoint with key NAME
aws_as() {
    local name="$1"
    shift
    AWS_ACCESS_KEY_ID=$(cat "$BATS_FILE_TMPDIR/$name.id") AWS_SECRET_ACCESS_KEY=$(cat "$BATS_FILE_TMPDIR/$name.secret") \
        AWS_DEFAULT_REGION=us-east-1 AWS_CONFIG_FILE="$BATS_FILE_TMPDIR/aws.config" AWS_SHARED_CREDENTIALS_FILE=/dev/null \
        /usr/local/bin/aws --endpoint-url "http://$ENDPOINT_HOST" "$@"
}

# rclone through the endpoint with key NAME
rclone_as() {
    local name="$1"
    shift
    RCLONE_CONFIG_OS_TYPE=s3 RCLONE_CONFIG_OS_PROVIDER=Minio RCLONE_CONFIG_OS_ENDPOINT="http://$ENDPOINT_HOST" \
        RCLONE_CONFIG_OS_ACCESS_KEY_ID=$(cat "$BATS_FILE_TMPDIR/$name.id") \
        RCLONE_CONFIG_OS_SECRET_ACCESS_KEY=$(cat "$BATS_FILE_TMPDIR/$name.secret") \
        RCLONE_CONFIG_OS_FORCE_PATH_STYLE=true RCLONE_CONFIG_OS_NO_CHECK_BUCKET=true RCLONE_CONFIG=/dev/null rclone "$@"
}

# Let mcli fail for calls whose arguments hold WORDS, as a Silo that is not
# ready yet answers; restore_mcli puts the real one back
fail_mcli() {
    [ -e /usr/local/bin/mcli.real ] || mv /usr/local/bin/mcli /usr/local/bin/mcli.real
    cat > /usr/local/bin/mcli << EOF
#!/bin/bash
case " \$* " in
    *" $1 "*) echo '{"status":"error","error":{"message":"Server not initialized"}}'; exit 1 ;;
esac
exec /usr/local/bin/mcli.real "\$@"
EOF
    chmod 755 /usr/local/bin/mcli
}

restore_mcli() {
    if [ -e /usr/local/bin/mcli.real ]; then
        mv -f /usr/local/bin/mcli.real /usr/local/bin/mcli
    fi
}

make_package() {
    local name="$1" tmp
    tmp=$(mktemp)
    sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$tmp"
    grep -q "^BACKUPS_INCREMENTAL=" "$tmp" || echo "BACKUPS_INCREMENTAL='yes'" >> "$tmp"
    v-add-user-package "$tmp" "$name" > /dev/null 2>&1 || true
    rm -f "$tmp"
}

setup_file() {
    local pass ip
    for u in "$U1" "$U2" "$U3" "$RES"; do
        v-delete-user "$u" > /dev/null 2>&1 || true
    done
    for p in "$PKG" "$PKG_RES" "$PKG_NONE"; do
        v-delete-user-package "$p" > /dev/null 2>&1 || true
    done

    # Silo, installed once from the pinned packages
    if [ "$(conf_value OBJECT_STORAGE_SYSTEM)" != 'silo' ]; then
        v-add-sys-silo > /dev/null
    fi

    # AWS CLI v2 for the client checks (a test tool, not pinned)
    if [ ! -x /usr/local/bin/aws ]; then
        curl -sSfL -o /root/awscliv2.zip https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip
        rm -rf /root/aws-install && mkdir -p /root/aws-install
        unzip -q /root/awscliv2.zip -d /root/aws-install
        /root/aws-install/aws/install > /dev/null
    fi
    printf '[default]\ns3 =\n    addressing_style = path\n' > "$BATS_FILE_TMPDIR/aws.config"
    v-add-sys-rclone > /dev/null

    make_package "$PKG"
    v-change-package-tenancy "$PKG" OBJECT_STORAGE yes > /dev/null
    v-change-package-tenancy "$PKG" OBJECT_STORAGE_BUCKETS 3 > /dev/null
    v-change-package-tenancy "$PKG" OBJECT_STORAGE_QUOTA 300 > /dev/null
    v-change-package-tenancy "$PKG" OBJECT_STORAGE_BACKUP yes > /dev/null
    make_package "$PKG_NONE"
    make_package "$PKG_RES"
    v-change-package-tenancy "$PKG_RES" USERS 5 > /dev/null
    v-change-package-tenancy "$PKG_RES" OBJECT_STORAGE yes > /dev/null
    v-change-package-tenancy "$PKG_RES" OBJECT_STORAGE_BUCKETS 4 > /dev/null
    v-change-package-tenancy "$PKG_RES" OBJECT_STORAGE_QUOTA 500 > /dev/null

    pass="Os-$(date +%s)-Pass"
    v-add-user "$U1" "$pass" "$U1@example.invalid" "$PKG" > /dev/null
    v-add-user "$U2" "$pass" "$U2@example.invalid" "$PKG" > /dev/null
    v-add-user "$RES" "$pass" "$RES@example.invalid" "$PKG_RES" > /dev/null
    v-change-user-role "$RES" reseller > /dev/null

    # The endpoint, without Let's Encrypt; the domain's address in /etc/hosts
    v-change-sys-silo-endpoint "$ENDPOINT_HOST" no > /dev/null
    # web.conf holds the NAT address; NGINX listens on the internal one
    ip=$(v-list-web-domain "$(conf_value ROOT_USER)" "$ENDPOINT_HOST" json | jq -r '.[].IP')
    for file in "$ARCPANEL"/data/ips/*; do
        if grep -q "NAT='$ip'" "$file"; then
            ip=$(basename "$file")
        fi
    done
    sed -i "/ $ENDPOINT_HOST\$/d" /etc/hosts
    echo "$ip $ENDPOINT_HOST" >> /etc/hosts

    cp "$ARCPANEL/conf/restic.conf" "$STATE.restic" 2> /dev/null || true
}

teardown_file() {
    restore_mcli
    rm -rf /backup/arcbats-restic
    for u in "$U1" "$U2" "$U3" "$RES"; do
        v-delete-user "$u" > /dev/null 2>&1 || true
    done
    for p in "$PKG" "$PKG_RES" "$PKG_NONE"; do
        v-delete-user-package "$p" > /dev/null 2>&1 || true
    done
    v-change-sys-silo-endpoint none > /dev/null 2>&1 || true
    v-delete-web-domain "$(conf_value ROOT_USER)" "$ENDPOINT_HOST" > /dev/null 2>&1 || true
    sed -i "/ $ENDPOINT_HOST\$/d" /etc/hosts
    v-delete-backup-host-s3 > /dev/null 2>&1 || true
    if [ -s "$STATE.restic" ]; then
        cp "$STATE.restic" "$ARCPANEL/conf/restic.conf"
    fi
}

@test "object storage: Silo runs from the pinned packages on localhost as user silo" {
    source "$ARCPANEL/install/upgrade/upgrade.conf"
    run dpkg-query -W -f='${Version}' silo
    assert_output "$silo_v"
    run systemctl is-active arcpanel-silo
    assert_output active
    run systemctl is-enabled silo
    refute_output enabled
    run bash -c "ss -ltnH '( sport = :9310 or sport = :9311 )' | awk '{print \$4}' | sort -u"
    refute_output --partial '0.0.0.0'
    refute_output --partial '*:'
    run ps -o user= -C silo
    assert_output --partial silo
    run stat -c '%U %a' /etc/arcpanel/silo.env
    assert_output 'root 600'
    run stat -c '%U %a' "$(conf_value SILO_DATA)"
    assert_output 'silo 700'
    run v-list-sys-config json
    assert_equal "$(jq -r .config.OBJECT_STORAGE_SYSTEM <<< "$output")" silo
    assert_equal "$(jq -r .config.SILO_HOSTNAME <<< "$output")" "$ENDPOINT_HOST"
    run v-list-sys-silo json
    assert_equal "$(jq -r .STATE <<< "$output")" running
    assert_equal "$(jq -r .ENDPOINT <<< "$output")" "http://$ENDPOINT_HOST"
    run grep -c "^PORT='9310' PROTO='tcp' APP='system'" "$ARCPANEL/data/ports/.reserved.conf"
    assert_output 1
}

@test "object storage: a package whose checksum does not match installs nothing" {
    local conf="$ARCPANEL/install/upgrade/upgrade.conf" before
    before=$(dpkg-query -W -f='${Version}' silo)
    cp "$conf" "$BATS_TEST_TMPDIR/upgrade.conf"
    sed -i "s/^silo_v=.*/silo_v='0.0.0-arcbats'/; s/^silo_sha256=.*/silo_sha256='$(printf '0%.0s' $(seq 1 64))'/" "$conf"
    run v-add-sys-silo
    cp "$BATS_TEST_TMPDIR/upgrade.conf" "$conf"
    assert_failure 15
    assert_equal "$(dpkg-query -W -f='${Version}' silo)" "$before"
}

@test "object storage: data paths in the home, the panel or a used folder are refused" {
    run v-add-sys-silo /home/arcbats-silo
    assert_failure 10
    run v-add-sys-silo /usr/local/arcpanel/data/silo-x
    assert_failure 10
}

@test "object storage: buckets need a valid free name and the package" {
    run v-add-user-bucket "$U1" Bad_Name 10
    assert_failure 2
    run v-add-user-bucket "$U1" ab 10
    assert_failure 2
    run v-add-user-bucket "$U1" "$B1" 100 yes
    assert_success
    run v-add-user-bucket "$U2" "$B1" 10
    assert_failure 4
    v-change-user-package "$U2" "$PKG_NONE" > /dev/null
    run v-add-user-bucket "$U2" arcbats-other 10
    assert_failure 10
    v-change-user-package "$U2" "$PKG" > /dev/null
    run v-list-user-buckets "$U1" json
    assert_equal "$(jq -r ".\"$B1\".QUOTA" <<< "$output")" 100
    assert_equal "$(jq -r ".\"$B1\".VERSIONING" <<< "$output")" yes
    run mcli_root quota info "arc/$B1" --json
    assert_equal "$(jq -r .quota <<< "$output")" $((100 * 1024 * 1024))
}

@test "object storage: the bucket count and the storage of the package are hard limits" {
    run v-add-user-bucket "$U1" "$B2" 250
    assert_failure 8
    assert_output --partial OBJECT_STORAGE_QUOTA
    run v-add-user-bucket "$U1" "$B2" 50
    assert_success
    run v-add-user-bucket "$U1" arcbats-third 10
    assert_success
    run v-add-user-bucket "$U1" arcbats-fourth 10
    assert_failure 8
    assert_output --partial OBJECT_STORAGE_BUCKETS
    run v-change-user-bucket "$U1" "$B2" 300 no
    assert_failure 8
    run v-delete-user-bucket "$U1" arcbats-third
    assert_success
    run v-list-user-object-storage "$U1" json
    assert_equal "$(jq -r .BUCKETS <<< "$output")" 2
    assert_equal "$(jq -r .QUOTA_USED <<< "$output")" 150
    assert_equal "$(jq -r .QUOTA_LIMIT <<< "$output")" 300
}

@test "object storage: an S3 key is shown once and never kept" {
    run v-add-user-s3-key "$U1" "app key" '' rw '' json
    assert_success
    save_key u1rw "$output"
    secret=$(cat "$BATS_FILE_TMPDIR/u1rw.secret")
    [ "${#secret}" -ge 20 ]
    run grep -rF -- "$secret" "$ARCPANEL/data" "$ARCPANEL/log"
    assert_failure
    run v-list-user-s3-keys "$U1" json
    assert_equal "$(jq -r ".\"$(key_id u1rw)\".NAME" <<< "$output")" 'app key'
    refute_output --partial "$secret"
}

@test "object storage: rclone and the AWS CLI upload and download through the endpoint" {
    head -c 524288 /dev/urandom > "$BATS_FILE_TMPDIR/half.bin"
    run rclone_as u1rw copyto "$BATS_FILE_TMPDIR/half.bin" "os:$B1/rclone.bin"
    assert_success
    run aws_as u1rw s3 cp "$BATS_FILE_TMPDIR/half.bin" "s3://$B1/aws.bin"
    assert_success
    run aws_as u1rw s3 cp "s3://$B1/rclone.bin" "$BATS_FILE_TMPDIR/back.bin"
    assert_success
    run cmp "$BATS_FILE_TMPDIR/half.bin" "$BATS_FILE_TMPDIR/back.bin"
    assert_success
    run aws_as u1rw s3 ls
    assert_output --partial "$B1"
    assert_output --partial "$B2"
}

@test "object storage: an account sees and reaches only its own buckets" {
    v-add-user-bucket "$U2" arcbats-u2 10 > /dev/null
    run v-add-user-s3-key "$U2" other '' rw '' json
    save_key u2rw "$output"
    run aws_as u2rw s3 ls
    assert_output --partial arcbats-u2
    refute_output --partial "$B1"
    run aws_as u2rw s3 ls "s3://$B1"
    assert_failure
    run aws_as u2rw s3 cp "s3://$B1/aws.bin" "$BATS_TEST_TMPDIR/x.bin"
    assert_failure
    run aws_as u1rw s3 ls s3://arcbats-u2
    assert_failure
}

@test "object storage: keys limited to a bucket or to reading stay limited" {
    run v-add-user-s3-key "$U1" readonly "$B1" ro "$(date -u -d '+30 days' +%F)" json
    assert_success
    save_key u1ro "$output"
    run v-add-user-s3-key "$U1" files "$B2" rw '' json
    save_key u1b2 "$output"
    run aws_as u1ro s3 cp "s3://$B1/aws.bin" "$BATS_TEST_TMPDIR/ro.bin"
    assert_success
    run aws_as u1ro s3 cp "$BATS_FILE_TMPDIR/half.bin" "s3://$B1/ro.bin"
    assert_failure
    run aws_as u1b2 s3 cp "$BATS_FILE_TMPDIR/half.bin" "s3://$B2/ok.bin"
    assert_success
    run aws_as u1b2 s3 ls "s3://$B1"
    assert_failure
    run v-list-user-s3-keys "$U1" json
    assert_equal "$(jq -r ".\"$(key_id u1ro)\".ACCESS" <<< "$output")" ro
    assert_equal "$(jq -r ".\"$(key_id u1ro)\".EXPIRED" <<< "$output")" no
}

@test "object storage: bucket creation and policies through S3 are denied" {
    run aws_as u1rw s3 mb s3://arcbats-sneaky
    assert_failure
    run aws_as u1rw s3api put-bucket-policy --bucket "$B1" --policy '{"Version":"2012-10-17","Statement":[]}'
    assert_failure
    run aws_as u1rw s3 rb "s3://$B2" --force
    assert_failure
}

@test "object storage: deleting a bucket deletes its keys, so a new bucket of that name stays out of reach" {
    run v-delete-user-bucket "$U1" "$B2"
    assert_success
    run v-list-user-s3-keys "$U1" json
    refute_output --partial "$(key_id u1b2)"
    run v-add-user-bucket "$U2" "$B2" 10
    assert_success
    run aws_as u1b2 s3 ls "s3://$B2"
    assert_failure
    v-delete-user-bucket "$U2" "$B2" > /dev/null
}

@test "object storage: one object above the quota is refused at once" {
    v-add-user-bucket "$U1" "$B2" 1 > /dev/null
    head -c 1572864 /dev/urandom > "$BATS_FILE_TMPDIR/big.bin"
    run aws_as u1rw s3 cp "$BATS_FILE_TMPDIR/big.bin" "s3://$B2/big.bin"
    assert_failure
    assert_output --partial 'QuotaExceeded'
}

@test "object storage: uploads that add up beyond the quota are refused after the next scan" {
    local refused=no
    head -c 700000 /dev/urandom > "$BATS_FILE_TMPDIR/part.bin"
    aws_as u1rw s3 cp "$BATS_FILE_TMPDIR/part.bin" "s3://$B2/p0.bin" > /dev/null
    for i in $(seq 1 15); do
        sleep 10
        if ! aws_as u1rw s3 cp "$BATS_FILE_TMPDIR/part.bin" "s3://$B2/p$i.bin" > /dev/null 2>&1; then
            refused=yes
            break
        fi
    done
    assert_equal "$refused" yes
}

@test "object storage: a repair refuses writes to a bucket whose objects and unfinished uploads pass its quota (E39 W5 N25)" {
    local b=arcbats-n25 uid
    v-add-user-bucket "$U1" "$b" 1 > /dev/null
    # Each part is below the quota and Silo does not count unfinished uploads
    head -c 700000 /dev/urandom > "$BATS_TEST_TMPDIR/part.bin"
    uid=$(aws_as u1rw s3api create-multipart-upload --bucket "$b" --key mp.bin --query UploadId --output text)
    for n in 1 2; do
        run aws_as u1rw s3api upload-part --bucket "$b" --key mp.bin --part-number "$n" --upload-id "$uid" --body "$BATS_TEST_TMPDIR/part.bin"
        assert_success
    done

    run v-check-sys-silo no
    assert_output --partial "$U1|bucket|$b is over its quota"
    run v-list-user-bucket "$U1" "$b" json
    refute_output --partial '"FULL": "yes"'
    run v-check-sys-silo repair
    assert_success
    run v-list-user-bucket "$U1" "$b" json
    assert_output --partial '"FULL": "yes"'
    run aws_as u1rw s3 cp "$BATS_TEST_TMPDIR/part.bin" "s3://$b/more.bin"
    assert_failure
    assert_output --partial 'AccessDenied'

    # Deleting is still allowed; below the quota the next repair lifts it
    run aws_as u1rw s3api abort-multipart-upload --bucket "$b" --key mp.bin --upload-id "$uid"
    assert_success
    v-check-sys-silo repair > /dev/null
    run v-list-user-bucket "$U1" "$b" json
    assert_output --partial '"FULL": "no"'
    run aws_as u1rw s3 cp "$BATS_TEST_TMPDIR/part.bin" "s3://$b/more.bin"
    assert_success
    v-delete-user-bucket "$U1" "$b" > /dev/null
}

@test "object storage: suspending the account makes its keys fail until it is unsuspended" {
    run v-suspend-user "$U1"
    assert_success
    run aws_as u1rw s3 ls "s3://$B1"
    assert_failure
    run v-add-user-bucket "$U1" arcbats-while-suspended 1
    assert_failure 5
    run v-unsuspend-user "$U1"
    assert_success
    run aws_as u1rw s3 ls "s3://$B1"
    assert_success
}

@test "object storage: a reseller cannot sell more buckets or storage than its plan" {
    run v-check-reseller-quota "$RES" "$PKG"
    assert_success
    v-change-package-tenancy "$PKG_NONE" OBJECT_STORAGE yes > /dev/null
    v-change-package-tenancy "$PKG_NONE" OBJECT_STORAGE_QUOTA 1G > /dev/null
    run v-check-reseller-quota "$RES" "$PKG_NONE"
    assert_failure 8
    assert_output --partial OBJECT_STORAGE_QUOTA
    v-change-package-tenancy "$PKG_NONE" OBJECT_STORAGE_QUOTA 100 > /dev/null
    v-change-package-tenancy "$PKG_NONE" OBJECT_STORAGE_BUCKETS unlimited > /dev/null
    run v-check-reseller-quota "$RES" "$PKG_NONE"
    assert_failure 8
    assert_output --partial OBJECT_STORAGE_BUCKETS
    v-change-package-tenancy "$PKG_NONE" OBJECT_STORAGE no > /dev/null
    v-change-package-tenancy "$PKG_NONE" OBJECT_STORAGE_BUCKETS 3 > /dev/null
}

@test "object storage: the repair recreates what Silo lost and records usage" {
    mcli_root rb --force --dangerous "arc/$B2" > /dev/null
    run v-check-sys-silo no
    assert_output --partial "$U1|bucket|$B2 is missing"
    run v-check-sys-silo repair
    assert_success
    run mcli_root stat "arc/$B2"
    assert_success
    run v-check-sys-silo no
    assert_output ''
    run v-list-user-buckets "$U1" json
    [ "$(jq -r ".\"$B1\".USED" <<< "$output")" -gt 0 ]
}

@test "object storage: rebuilding the account restores its object storage user" {
    mcli_root admin user remove arc "arc-$U1" > /dev/null
    run v-rebuild-user "$U1"
    assert_success
    run mcli_root admin user info arc "arc-$U1" --json
    assert_equal "$(jq -r .policyName <<< "$output")" "arc-user-$U1"
    # Its keys went with the user and cannot come back
    run v-list-user-s3-keys "$U1" json
    assert_output '{}'
    run v-add-user-s3-key "$U1" "app key" '' rw '' json
    save_key u1rw "$output"
    run aws_as u1rw s3 ls "s3://$B1"
    assert_success
}

@test "object storage: a key listing that fails keeps every key and its record" {
    local before
    before=$(v-list-user-s3-keys "$U1" json | jq -r 'keys | sort | join(",")')
    [ -n "$before" ]
    fail_mcli 'svcacct list'
    for _ in 1 2 3 4; do
        run v-check-sys-silo repair json
        assert_success
        assert_output --partial 'could not be listed'
    done
    restore_mcli
    assert_equal "$(v-list-user-s3-keys "$U1" json | jq -r 'keys | sort | join(",")')" "$before"
    run aws_as u1rw s3 ls "s3://$B1"
    assert_success
    run v-check-sys-silo repair
    assert_output ''
    assert_equal "$(v-list-user-s3-keys "$U1" json | jq -r 'keys | sort | join(",")')" "$before"
}

@test "object storage: a repair right after a restart keeps every key" {
    local before
    before=$(v-list-user-s3-keys "$U1" json | jq -r 'keys | sort | join(",")')
    systemctl restart arcpanel-silo
    run v-check-sys-silo repair
    assert_success
    assert_equal "$(v-list-user-s3-keys "$U1" json | jq -r 'keys | sort | join(",")')" "$before"
    run aws_as u1rw s3 ls "s3://$B1"
    assert_success
}

@test "object storage: a key record and a service account that disagree are dropped after three repairs" {
    local gone extra
    run v-add-user-s3-key "$U1" "gone key" '' ro '' json
    assert_success
    gone=$(jq -r .KEY <<< "$output")
    mcli_root admin user svcacct remove arc "$gone" > /dev/null
    extra=$(mcli_root admin user svcacct add arc "arc-$U1" --json | jq -r .accessKey)
    [ -n "$extra" ]
    for _ in 1 2; do
        run v-check-sys-silo repair
        assert_output --partial "S3 key $gone is missing"
        assert_output --partial "service account $extra has no S3 key record"
        run v-list-user-s3-keys "$U1" json
        assert_output --partial "\"$gone\""
        run mcli_root admin user svcacct info arc "$extra"
        assert_success
    done
    run v-check-sys-silo repair
    run v-list-user-s3-keys "$U1" json
    refute_output --partial "\"$gone\""
    run mcli_root admin user svcacct info arc "$extra"
    assert_failure
    run v-check-sys-silo no
    assert_output ''
}

@test "object storage: a backup carries the buckets and their objects; a restore brings them back" {
    run v-backup-user "$U1"
    assert_success
    backup=$(v-list-user-backups "$U1" json | jq -r 'keys | sort | last')
    run tar -tf "/backup/$backup" ./arcpanel/buckets.conf "./object_storage/$B1.tar.zst"
    assert_success
    v-delete-user-bucket "$U1" "$B1" > /dev/null
    run v-restore-user "$U1" "$backup" no no no no no yes no
    assert_success
    run v-list-user-buckets "$U1" json
    assert_equal "$(jq -r ".\"$B1\".QUOTA" <<< "$output")" 100
    run aws_as u1rw s3 cp "s3://$B1/aws.bin" "$BATS_TEST_TMPDIR/restored.bin"
    assert_success
    run cmp "$BATS_FILE_TMPDIR/half.bin" "$BATS_TEST_TMPDIR/restored.bin"
    assert_success
    rm -f "/backup/$backup"
}

@test "object storage: a restore skips buckets beyond the limits of the package" {
    run v-backup-user "$U1"
    assert_success
    backup=$(v-list-user-backups "$U1" json | jq -r 'keys | sort | last')
    v-delete-user-bucket "$U1" "$B1" > /dev/null
    v-change-package-tenancy "$PKG" OBJECT_STORAGE_QUOTA 1 > /dev/null
    run v-restore-user "$U1" "$backup" no no no no no yes no
    v-change-package-tenancy "$PKG" OBJECT_STORAGE_QUOTA 300 > /dev/null
    assert_success
    assert_output --partial "Bucket $B1 skipped: the bucket quotas would reach"
    run v-list-user-buckets "$U1" json
    refute_output --partial "\"$B1\""
    run v-restore-user "$U1" "$backup" no no no no no yes no
    assert_success
    run v-list-user-buckets "$U1" json
    assert_equal "$(jq -r ".\"$B1\".QUOTA" <<< "$output")" 100
    rm -f "/backup/$backup"
}

@test "object storage: an account deleted while Silo is down leaves nothing once Silo is back" {
    local key
    v-add-user "$U3" "Os-$(date +%s)-Pass" "$U3@example.invalid" "$PKG" > /dev/null
    v-add-user-bucket "$U3" arcbats-u3 10 > /dev/null
    key=$(v-add-user-s3-key "$U3" k3 '' rw '' json | jq -r .KEY)
    [ -n "$key" ]
    systemctl stop arcpanel-silo
    run v-delete-user "$U3"
    systemctl start arcpanel-silo
    assert_success
    assert_file_not_exists "$ARCPANEL/data/users/$U3/buckets.conf"
    run mcli_root stat arc/arcbats-u3
    assert_success
    run v-check-sys-silo no
    assert_output --partial "$U3|orphan|"
    run v-check-sys-silo repair
    assert_success
    run mcli_root stat arc/arcbats-u3
    assert_failure
    run mcli_root admin user svcacct info arc "$key"
    assert_failure
    run mcli_root admin user info arc "arc-$U3"
    assert_failure
    run v-check-sys-silo no
    assert_output ''
}

@test "object storage: incremental backups go to an S3 backup host" {
    v-add-user-bucket "$RES" arcbats-restic 200 > /dev/null
    run v-add-user-s3-key "$RES" restic arcbats-restic rw '' json
    save_key restic "$output"
    printf 'AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\n' "$(cat "$BATS_FILE_TMPDIR/restic.id")" "$(cat "$BATS_FILE_TMPDIR/restic.secret")" > "$BATS_TEST_TMPDIR/s3.env"
    run v-add-backup-host-s3 http://127.0.0.1:9310 arcbats-restic/server "$BATS_TEST_TMPDIR/s3.env" us-east-1 3
    assert_success
    run grep "^REPO=" "$ARCPANEL/conf/restic.conf"
    assert_output "REPO='rclone:arcpanel-s3:arcbats-restic/server'"
    run v-list-backup-host-restic json
    assert_equal "$(jq -r '.restic | "\(.S3_ENDPOINT) \(.S3_REGION)"' <<< "$output")" 'http://127.0.0.1:9310 us-east-1'
    refute_output --partial "$(sed -n 's/^AWS_SECRET_ACCESS_KEY=//p' "$BATS_TEST_TMPDIR/s3.env")"
    # A change of the retention keeps the connection
    run v-add-backup-host-restic rclone:arcpanel-s3:arcbats-restic/server 5 -1 -1 -1 -1
    assert_success
    run grep -c '^S3_' "$ARCPANEL/conf/restic.conf"
    assert_output 3
    run stat -c %a /root/.config/rclone/rclone.conf
    assert_output 600
    run v-backup-user-restic "$U2"
    assert_success
    run v-list-user-backups-restic "$U2" json
    [ "$(jq 'length' <<< "$output")" -ge 1 ]
    run v-delete-backup-host-s3
    assert_success
    run grep -c '^\[arcpanel-s3\]' /root/.config/rclone/rclone.conf
    assert_output 0
}

@test "object storage: the cross-user list covers every account or a reseller's tree" {
    run v-list-sys-buckets '' '' json
    assert_success
    assert_equal "$(jq -r ".OBJECTS.\"$U1\".\"$B1\".QUOTA" <<< "$output")" 100
    assert_equal "$(jq -r ".OBJECTS.\"$U2\".\"arcbats-u2\".BUCKET" <<< "$output")" arcbats-u2
    run v-list-sys-buckets "$RES" '' json
    assert_equal "$(jq -r '.USERS | join(",")' <<< "$output")" "$RES"
}

@test "object storage: a restic snapshot stages the objects on the backup disk; a restore brings them back" {
    local snapshot repo=/backup/arcbats-restic
    echo restic-object > "$BATS_TEST_TMPDIR/restic.txt"
    mcli_root cp "$BATS_TEST_TMPDIR/restic.txt" arc/arcbats-u2/restic.txt > /dev/null
    rm -rf "$repo"
    mkdir -p "$repo"
    run v-add-backup-host-restic "$repo" 3 -1 -1 -1 -1
    assert_success
    rm -f "$ARCPANEL/data/users/$U2/restic.conf"
    run v-backup-user-restic "$U2"
    assert_success
    assert_dir_not_exists "/backup/arcpanel-objects-$U2"
    assert_dir_not_exists "$ARCPANEL/data/silo/backup/$U2"
    snapshot=$(restic --repo "$repo/$U2" --password-file "$ARCPANEL/data/users/$U2/restic.conf" snapshots --json | jq -r 'last.short_id')
    run restic --repo "$repo/$U2" --password-file "$ARCPANEL/data/users/$U2/restic.conf" cat snapshot "$snapshot"
    assert_output --partial "\"/backup/arcpanel-objects-$U2\""
    v-delete-user-bucket "$U2" arcbats-u2 > /dev/null
    # Everything, as the panel restores a snapshot
    run v-restore-user-restic "$U2" "$snapshot"
    assert_success
    assert_output --partial 'Restore object storage buckets'
    run mcli_root cat arc/arcbats-u2/restic.txt
    assert_output restic-object
    run v-list-user-buckets "$U2" json
    assert_output --partial '"arcbats-u2"'
    v-delete-backup-host-restic > /dev/null
    rm -rf "$repo"
    # A later repository of the account needs a new password and init
    rm -f "$ARCPANEL/data/users/$U2/restic.conf"
}

@test "object storage: removing Silo is refused while buckets exist" {
    run v-delete-sys-silo
    assert_failure 7
    run systemctl is-active arcpanel-silo
    assert_output active
}

@test "object storage: deleting an account deletes its buckets, keys and user" {
    run v-delete-user "$U2"
    assert_success
    run mcli_root stat arc/arcbats-u2
    assert_failure
    run mcli_root admin user info arc "arc-$U2"
    assert_failure
    assert_file_not_exists "$ARCPANEL/data/users/$U2/buckets.conf"
}
