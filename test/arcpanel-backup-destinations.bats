#!/usr/bin/env bats
# Backup destinations (E48 M1 and M3,
# docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md §4).
# Run as root on a test server with rclone, restic, age and sshd on port 22.
# Remote drivers run against services on this server: an OpenSSH account
# (sftp), rclone serve webdav (webdav) and Silo when it is installed (s3).
# Google Drive, Dropbox and OneDrive are checked up to their rclone
# configuration; the runbook covers real accounts.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
DEST_DIR=$ARCPANEL/data/backup/destinations
BD_USER=arcbatsbd
BD_PACKAGE=arcbats-bd-storage
SFTP_USER=arcbatsbdsftp
SFTP_HOME=/var/tmp/arcbatsbdsftp
DAV_DIR=/var/tmp/arcbatsbddav
DAV_PORT=18191
LOCAL_DIR=/srv/arcbats-bd
SECRET=SECRET-BD-7731

# Destinations and jobs of this suite start with arcbats-
cleanup() {
    local id name
    for id in $(v-list-backup-jobs json 2> /dev/null | jq -r 'to_entries[] | select(.value.NAME | startswith("arcbats")) | .key'); do
        v-delete-backup-job "$id" > /dev/null 2>&1
    done
    for name in $(v-list-backup-destinations json 2> /dev/null | jq -r 'keys[] | select(startswith("arcbats"))'); do
        v-delete-backup-destination "$name" > /dev/null 2>&1
    done
}

# options NAME KEY=VALUE…: write an options file
options() {
    local file="$BATS_TEST_TMPDIR/$1.options" pair
    shift
    : > "$file"
    for pair in "$@"; do
        printf "%s='%s'\n" "${pair%%=*}" "${pair#*=}" >> "$file"
    done
    echo "$file"
}

# secrets NAME KEY=VALUE…: write a secrets folder
secrets() {
    local dir="$BATS_TEST_TMPDIR/$1.secrets" pair
    shift
    mkdir -p "$dir"
    for pair in "$@"; do
        printf '%s' "${pair#*=}" > "$dir/${pair%%=*}"
    done
    echo "$dir"
}

# The rclone configuration a destination gets for one run
rclone_config() {
    (
        set +e
        trap - EXIT
        . /etc/arcpanel/arcpanel.conf
        . "$ARCPANEL/func/main.sh"
        . "$ARCPANEL/func/secret.sh"
        . "$ARCPANEL/func/backup-driver.sh"
        bd_session
        conf=$(bd_rclone_config "$1")
        cat "$conf"
    )
}

setup_file() {
    local pass tmp
    cleanup
    v-delete-user "$BD_USER" > /dev/null 2>&1 || true
    userdel -r "$SFTP_USER" > /dev/null 2>&1 || true
    rm -rf "$SFTP_HOME" "$DAV_DIR" "$LOCAL_DIR"
    pkill -f "rclone serve webdav $DAV_DIR" > /dev/null 2>&1 || true

    useradd -m -d "$SFTP_HOME" -s /bin/bash "$SFTP_USER"
    install -d -m 700 -o "$SFTP_USER" -g "$SFTP_USER" "$SFTP_HOME/.ssh" "$SFTP_HOME/backup"
    ssh-keygen -q -t ed25519 -N '' -f "$BATS_FILE_TMPDIR/sftp-key" > /dev/null
    install -m 600 -o "$SFTP_USER" -g "$SFTP_USER" "$BATS_FILE_TMPDIR/sftp-key.pub" "$SFTP_HOME/.ssh/authorized_keys"

    mkdir -p "$DAV_DIR"
    setsid nohup rclone serve webdav "$DAV_DIR" --addr "127.0.0.1:$DAV_PORT" --user davuser --pass "$SECRET" \
        > "$BATS_FILE_TMPDIR/dav.log" 2>&1 < /dev/null &
    sleep 2

    # A package with object storage, for the s3 driver against Silo
    tmp=$(mktemp)
    cp "$ARCPANEL/data/packages/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$BD_PACKAGE" > /dev/null 2>&1 || true
    rm -f "$tmp"
    v-change-package-tenancy "$BD_PACKAGE" OBJECT_STORAGE yes > /dev/null 2>&1 || true
    pass="Bd-$(date +%s)-Pass"
    v-add-user "$BD_USER" "$pass" "$BD_USER@example.invalid" "$BD_PACKAGE" > /dev/null
}

teardown_file() {
    cleanup
    pkill -f "rclone serve webdav $DAV_DIR" > /dev/null 2>&1 || true
    v-delete-user "$BD_USER" > /dev/null 2>&1 || true
    v-delete-user-package "$BD_PACKAGE" > /dev/null 2>&1 || true
    userdel -r "$SFTP_USER" > /dev/null 2>&1 || true
    rm -rf "$SFTP_HOME" "$DAV_DIR" "$LOCAL_DIR"
}

@test "destinations: names, drivers, fields and required values are checked" {
    run v-add-backup-destination Bad local "$(options a PATH=$LOCAL_DIR)"
    assert_failure 2
    run v-add-backup-destination arcbats-x floppy "$(options b PATH=$LOCAL_DIR)"
    assert_failure 2
    run v-add-backup-destination arcbats-x local "$(options c PATH=$LOCAL_DIR HOST=example.com)"
    assert_failure 2
    assert_output --partial 'HOST is not an option of the local driver'
    run v-add-backup-destination arcbats-x local "$(options d PATH=/home/alice)"
    assert_failure 2
    run v-add-backup-destination arcbats-x sftp "$(options e HOST=127.0.0.1)"
    assert_failure 1
    assert_output --partial 'USERNAME is required'
    run v-add-backup-destination arcbats-x sftp "$(options f HOST=127.0.0.1 USERNAME=u)"
    assert_failure 1
    assert_output --partial 'PASSWORD or PRIVATE_KEY is required'
    run v-add-backup-destination arcbats-x gdrive "$(options g AUTH=token)" "$(secrets g TOKEN=not-json)"
    assert_failure 2
    assert_output --partial 'invalid TOKEN'
    assert_file_not_exists "$DEST_DIR/arcbats-x.conf"
}

@test "destinations: a local destination is added, checked and listed without secrets" {
    run v-add-backup-destination arcbats-local local "$(options l PATH=$LOCAL_DIR DESCRIPTION='Second disk')"
    assert_success
    run stat -c '%a %U' "$DEST_DIR/arcbats-local.conf" "$DEST_DIR/arcbats-local.key.age" "$LOCAL_DIR"
    assert_output $'600 root\n600 root\n711 root'
    run sh -c "v-list-backup-destination arcbats-local json | jq -r '.\"arcbats-local\" | \"\(.STATE) \(.ENCRYPT) \(.DESCRIPTION)\"'"
    assert_output 'unchecked no Second disk'
    run v-check-backup-destination arcbats-local '' json
    assert_success
    assert_equal "$(jq -r '."arcbats-local".OK' <<< "$output")" yes
    run sh -c "v-list-backup-destinations json | jq -r '.\"arcbats-local\".STATE'"
    assert_output ok
}

@test "destinations: webdav through rclone, its password encrypted and never printed" {
    run v-add-backup-destination arcbats-dav webdav \
        "$(options dav URL=http://127.0.0.1:$DAV_PORT USERNAME=davuser PATH=arcbats)" "$(secrets dav PASSWORD=$SECRET)"
    assert_success
    run sh -c "v-list-backup-destination arcbats-dav json | jq -r '.\"arcbats-dav\" | \"\(.ENCRYPT) \(.SECRETS)\"'"
    assert_output 'yes PASSWORD'
    run grep -rl "$SECRET" "$DEST_DIR"
    assert_failure
    run v-list-backup-destinations json
    refute_output --partial "$SECRET"
    run v-check-backup-destination arcbats-dav
    assert_success
    # A wrong password fails the check and marks the destination failing
    run v-change-backup-destination arcbats-dav "$(options dav2)" "$(secrets dav2 PASSWORD=wrong)"
    assert_success
    run sh -c "v-list-backup-destination arcbats-dav json | jq -r '.\"arcbats-dav\".STATE'"
    assert_output unchecked
    run v-check-backup-destination arcbats-dav '' json
    assert_failure 15
    assert_equal "$(jq -r '."arcbats-dav".OK' <<< "$output")" no
    run v-change-backup-destination arcbats-dav "$(options dav3)" "$(secrets dav3 PASSWORD=$SECRET)"
    run v-check-backup-destination arcbats-dav
    assert_success
}

@test "destinations: sftp pins the host key the administrator accepts" {
    local key
    run v-add-backup-destination arcbats-sftp sftp \
        "$(options s HOST=127.0.0.1 USERNAME=$SFTP_USER PATH=$SFTP_HOME/backup)" "$(secrets s PRIVATE_KEY="$(cat "$BATS_FILE_TMPDIR/sftp-key")")"
    assert_success
    # Without an accepted key the check shows the server's key and fails
    run v-check-backup-destination arcbats-sftp '' json
    assert_failure 10
    key=$(jq -r '."arcbats-sftp".HOST_KEY' <<< "$output")
    [[ "$key" == SHA256:* ]]
    run v-check-backup-destination arcbats-sftp 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
    assert_failure 10
    run v-check-backup-destination arcbats-sftp "$key"
    assert_success
    run v-check-backup-destination arcbats-sftp
    assert_success
    # A host key that changes is refused
    sed -i "s#^HOST_KEY=.*#HOST_KEY='SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'#" "$DEST_DIR/arcbats-sftp.conf"
    run v-check-backup-destination arcbats-sftp
    assert_failure 10
    assert_output --partial 'host key changed'
    sed -i "s#^HOST_KEY=.*#HOST_KEY='$key'#" "$DEST_DIR/arcbats-sftp.conf"
    # A new host forgets the pinned key
    run v-change-backup-destination arcbats-sftp "$(options s2 HOST=localhost)"
    assert_success
    run sh -c "v-list-backup-destination arcbats-sftp json | jq -r '.\"arcbats-sftp\".HOST_KEY'"
    assert_output ''
}

@test "destinations: s3 against Silo on this server" {
    local out id secret
    if ! systemctl is-active --quiet arcpanel-silo; then
        skip "Silo is not installed"
    fi
    v-add-user-bucket "$BD_USER" arcbats-bd 100 > /dev/null 2>&1 || skip "the user may not have buckets"
    out=$(v-add-user-s3-key "$BD_USER" bd arcbats-bd rw '' json)
    id=$(jq -r '.KEY // empty' <<< "$out")
    secret=$(jq -r '.SECRET // empty' <<< "$out")
    [ -n "$id" ] && [ -n "$secret" ] || skip "no S3 key"
    run v-add-backup-destination arcbats-s3 s3 \
        "$(options s3 PROVIDER=Other ENDPOINT=http://127.0.0.1:9310 BUCKET=arcbats-bd ACCESS_KEY_ID="$id" PATH_STYLE=yes PATH=server)" \
        "$(secrets s3 SECRET_ACCESS_KEY="$secret")"
    assert_success
    run v-check-backup-destination arcbats-s3
    assert_success
    v-delete-backup-destination arcbats-s3 > /dev/null
    v-delete-user-bucket "$BD_USER" arcbats-bd > /dev/null 2>&1 || true
}

@test "destinations: OAuth drivers get their rclone configuration" {
    local token='{"access_token":"x","token_type":"Bearer","refresh_token":"y","expiry":"2026-01-01T00:00:00Z"}'
    run v-add-backup-destination arcbats-gd gdrive "$(options gd AUTH=token ROOT_FOLDER_ID=abc123 PATH=backups)" "$(secrets gd TOKEN="$token")"
    assert_success
    run rclone_config arcbats-gd
    assert_line 'type = drive'
    assert_line 'scope = drive.file'
    assert_line 'root_folder_id = abc123'
    assert_line --partial 'token = {"access_token":"x"'
    run v-add-backup-destination arcbats-db dropbox "$(options db PATH=arc)" "$(secrets db TOKEN="$token")"
    assert_success
    run rclone_config arcbats-db
    assert_line 'type = dropbox'
    run v-add-backup-destination arcbats-od onedrive "$(options od DRIVE_ID=b!xyz DRIVE_TYPE=business)" "$(secrets od TOKEN="$token")"
    assert_success
    run rclone_config arcbats-od
    assert_line 'drive_type = business'
    run v-add-backup-destination arcbats-sa gdrive "$(options sa AUTH=service-account SHARED_DRIVE_ID=0AB)" \
        "$(secrets sa SERVICE_ACCOUNT='{"type":"service_account","client_email":"x@y.iam.gserviceaccount.com"}')"
    assert_success
    run rclone_config arcbats-sa
    assert_line 'scope = drive'
    assert_line 'team_drive = 0AB'
    for name in arcbats-gd arcbats-db arcbats-od arcbats-sa; do
        v-delete-backup-destination "$name" > /dev/null
    done
}

@test "destinations: an encrypted archive is read with the recovery kit on a new record" {
    local id archive kit="$BATS_TEST_TMPDIR/kit.txt"
    run v-add-backup-destination arcbats-enc local "$(options enc PATH=$LOCAL_DIR/enc ENCRYPT=yes)"
    assert_success
    id=$(v-add-backup-job arcbats-enc archive "users:$BD_USER" daily:03:00 arcbats-enc)
    run v-backup-user "$BD_USER" no "$id"
    assert_success
    archive=$(ls "$LOCAL_DIR/enc" | grep "^$BD_USER\..*\.tar\.age$" | head -n1)
    [ -n "$archive" ]
    run tar -tf "$LOCAL_DIR/enc/$archive"
    assert_failure
    run v-export-backup-destination-key arcbats-enc "$kit"
    assert_success
    run stat -c %a "$kit"
    assert_output 600
    grep -q '^AGE-SECRET-KEY-' "$kit"
    run v-delete-backup-destination arcbats-enc
    assert_failure 7
    v-delete-backup-job "$id" > /dev/null
    run v-delete-backup-destination arcbats-enc
    assert_success
    # The record of the archive forgets the destination
    run grep -c "^BACKUP='${archive%.age}'" "$ARCPANEL/data/users/$BD_USER/backup.conf"
    assert_output 0
    # As on a new server: the kit opens the archives again
    run v-add-backup-destination arcbats-enc local "$(options enc2 PATH=$LOCAL_DIR/enc ENCRYPT=yes)" '' "$kit"
    assert_success
    run sh -c "v-list-backup-destination-archives arcbats-enc '$BD_USER' json | jq -r '.[0] | \"\(.ENCRYPTED) \(.REGISTERED)\"'"
    assert_output 'yes no'
    rm -f "/backup/${archive%.age}"
    run v-import-user-backup arcbats-enc "${archive%.age}"
    assert_success
    run tar -tf "/backup/${archive%.age}" ./pam
    assert_success
    run sh -c "v-list-backup-destination-archives arcbats-enc '$BD_USER' json | jq -r '.[0].REGISTERED'"
    assert_output yes
    rm -f "/backup/${archive%.age}"
}

@test "destinations: deleting a destination that a job targets is refused" {
    local id
    id=$(v-add-backup-job arcbats-refuse archive "users:$BD_USER" daily:03:00 arcbats-local)
    run v-delete-backup-destination arcbats-local
    assert_failure 7
    assert_output --partial "$id"
    v-delete-backup-job "$id" > /dev/null
    run v-delete-backup-destination arcbats-local
    assert_success
    assert_file_not_exists "$DEST_DIR/arcbats-local.conf"
    assert_file_not_exists "$DEST_DIR/arcbats-local.key.age"
}
