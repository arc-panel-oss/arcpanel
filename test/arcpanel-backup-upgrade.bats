#!/usr/bin/env bats
# The conversion of the backup settings of earlier versions to destinations
# and jobs: v-update-sys-backup-config (E48 §10.2,
# docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md).
# Run as root on a test server. The suite moves the server's backup
# destinations, jobs and old host files aside and puts them back afterwards.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
U=arcbatsup
PKG=arcbats-up
REMOTE=arcbatsup
RCLONE_DIR=/var/tmp/arcbatsup-rclone
REPO=/backup/restic-arcbatsup
CRONTAB=/var/spool/cron/crontabs/arcpanelweb
SAVE="$BATS_FILE_TMPDIR/save"
OLD_FILES='local.backup.conf ftp.backup.conf sftp.backup.conf b2.backup.conf rclone.backup.conf restic.conf'

dest_value() {
    v-list-backup-destination "$1" json | jq -r --arg n "$1" --arg k "$2" '.[$n][$k]'
}

job_by_name() {
    v-list-backup-jobs json | jq -r --arg n "$1" 'to_entries[] | select(.value.NAME == $n) | .key'
}

job_value() {
    v-list-backup-job "$1" json | jq -r --arg i "$1" --arg k "$2" '.[$i][$k]'
}

conf_value() {
    grep "^$1=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2
}

setup_file() {
    local f tmp pass
    # Leftovers of an interrupted run
    v-delete-user "$U" > /dev/null 2>&1 || true
    v-delete-user-package "$PKG" > /dev/null 2>&1 || true
    rm -rf "$RCLONE_DIR" "$REPO"

    # The state of the server, put back by teardown_file
    mkdir -p "$SAVE/conf"
    cp -p "$ARCPANEL/conf/arcpanel.conf" "$SAVE/arcpanel.conf"
    [ -f "$CRONTAB" ] && cp -p "$CRONTAB" "$SAVE/crontab"
    [ -d "$ARCPANEL/data/backup" ] && mv "$ARCPANEL/data/backup" "$SAVE/backup"
    for f in $OLD_FILES; do
        for g in "$ARCPANEL/conf/$f"*; do
            [ -e "$g" ] && mv "$g" "$SAVE/conf/"
        done
    done

    # A user of an incremental package with an archive record and a restic
    # password of earlier versions
    tmp=$(mktemp)
    sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$tmp"
    grep -q "^BACKUPS_INCREMENTAL=" "$tmp" || echo "BACKUPS_INCREMENTAL='yes'" >> "$tmp"
    v-add-user-package "$tmp" "$PKG" > /dev/null
    rm -f "$tmp"
    pass="Up-$(date +%s)-Pass"
    v-add-user "$U" "$pass" "$U@example.invalid" "$PKG" > /dev/null
    echo "BACKUP='$U.2026-10-01_05-10-00.tar' TYPE='local,ftp' SIZE='1' LANGUAGE='' TIME='05:10:00' DATE='2026-10-01'" \
        >> "$ARCPANEL/data/users/$U/backup.conf"
    echo "arcbats-plain-restic-password" > "$ARCPANEL/data/users/$U/restic.conf"

    # The hosts of earlier versions: the local folder, FTP, an rclone remote
    # and a restic repository in a folder
    mkdir -p "$RCLONE_DIR" "$REPO" /root/.config/rclone
    touch /root/.config/rclone/rclone.conf
    printf '\n[%s]\ntype = alias\nremote = %s\n' "$REMOTE" "$RCLONE_DIR" >> /root/.config/rclone/rclone.conf
    printf "HOST='ftp.example.invalid'\nUSERNAME='arcbats'\nPASSWORD='arcbats-ftp-password'\nBPATH='/arc'\nPORT='2121'\nTIME='05:00:00'\nDATE='2026-10-01'\n" \
        > "$ARCPANEL/conf/ftp.backup.conf"
    printf "HOST='%s'\nBPATH='/arc'\n" "$REMOTE" > "$ARCPANEL/conf/rclone.backup.conf"
    printf "REPO='%s'\nSNAPSHOTS='5'\nKEEP_DAILY='7'\nKEEP_WEEKLY='4'\nKEEP_MONTHLY='-1'\nKEEP_YEARLY='-1'\n" "$REPO" \
        > "$ARCPANEL/conf/restic.conf"
    sed -i -e "s/^BACKUP_SYSTEM=.*/BACKUP_SYSTEM='local,ftp,rclone'/" \
        -e "s/^BACKUP_INCREMENTAL=.*/BACKUP_INCREMENTAL='yes'/" "$ARCPANEL/conf/arcpanel.conf"
    grep -q '^BACKUP_INCREMENTAL=' "$ARCPANEL/conf/arcpanel.conf" || echo "BACKUP_INCREMENTAL='yes'" >> "$ARCPANEL/conf/arcpanel.conf"

    # The nightly cron lines of earlier versions
    if [ -f "$CRONTAB" ]; then
        sed -i '/v-update-backup-jobs/d' "$CRONTAB"
        sed -i -e '$a10 05 * * * sudo /usr/local/arcpanel/bin/v-backup-users > /dev/null 2>\&1' "$CRONTAB"
    fi

    v-update-sys-backup-config > "$BATS_FILE_TMPDIR/convert.log" 2>&1
}

teardown_file() {
    local f
    v-delete-user "$U" > /dev/null 2>&1 || true
    v-delete-user-package "$PKG" > /dev/null 2>&1 || true
    rm -rf "$ARCPANEL/data/backup"
    [ -d "$SAVE/backup" ] && mv "$SAVE/backup" "$ARCPANEL/data/backup"
    for f in $OLD_FILES; do
        rm -f "$ARCPANEL/conf/$f" "$ARCPANEL/conf/$f.migrated"
    done
    rm -f "$ARCPANEL/conf/ftp.backup.key"* "$ARCPANEL/conf/sftp.backup.key"*
    for f in "$SAVE/conf/"*; do
        [ -e "$f" ] && mv "$f" "$ARCPANEL/conf/"
    done
    cp -p "$SAVE/arcpanel.conf" "$ARCPANEL/conf/arcpanel.conf"
    # A file of another owner in a sticky folder: replaced, never opened (fs.protected_regular)
    [ -f "$SAVE/crontab" ] && rm -f "$CRONTAB" && cp -p "$SAVE/crontab" "$CRONTAB"
    sed -i "/^\[$REMOTE\]$/,/^remote = /d" /root/.config/rclone/rclone.conf
    rm -rf "$RCLONE_DIR" "$REPO"
}

@test "upgrade: the old hosts become destinations of the same names" {
    run cat "$BATS_FILE_TMPDIR/convert.log"
    assert_output --partial 'the ftp host is now the backup destination ftp'
    refute_output --partial '[ ! ]'

    assert_equal "$(dest_value local DRIVER)" local
    local backup
    backup=$(conf_value BACKUP)
    assert_equal "$(dest_value local PATH)" "${backup:-/backup}"
    assert_equal "$(dest_value ftp DRIVER)" ftp
    assert_equal "$(dest_value ftp HOST)" ftp.example.invalid
    assert_equal "$(dest_value ftp PORT)" 2121
    assert_equal "$(dest_value ftp USERNAME)" arcbats
    assert_equal "$(dest_value ftp PATH)" /arc
    assert_equal "$(dest_value ftp ENCRYPT)" no
    assert_equal "$(dest_value ftp SECRETS)" PASSWORD
    assert_equal "$(dest_value rclone DRIVER)" rclone
    assert_equal "$(dest_value rclone REMOTE)" "$REMOTE"
    assert_equal "$(dest_value rclone PATH)" arc
    assert_equal "$(dest_value rclone SECRETS)" CONFIG
    assert_equal "$(dest_value restic DRIVER)" local
    assert_equal "$(dest_value restic PATH)" "$REPO"

    # The secrets are encrypted, never stored in plain text
    run grep -r arcbats-ftp-password "$ARCPANEL/data/backup"
    assert_failure
    assert_file_exists "$ARCPANEL/conf/ftp.backup.conf.migrated"
    assert_file_exists "$ARCPANEL/conf/rclone.backup.conf.migrated"
    assert_file_exists "$ARCPANEL/conf/restic.conf.migrated"
    assert_file_not_exists "$ARCPANEL/conf/ftp.backup.conf"
}

@test "upgrade: the nightly runs become the jobs of earlier times and the dispatcher runs them" {
    local archives snapshots
    archives=$(job_by_name 'Nightly archives')
    snapshots=$(job_by_name 'Nightly snapshots')
    [ -n "$archives" ]
    [ -n "$snapshots" ]
    assert_equal "$(job_value "$archives" ENGINE)" archive
    assert_equal "$(job_value "$archives" SCOPE)" all
    assert_equal "$(job_value "$archives" SCHEDULE)" daily:05:10
    run sh -c "v-list-backup-job '$archives' json | jq -r '.[].TARGETS' | tr ',' '\n' | cut -d: -f1 | sort | tr '\n' ' '"
    assert_output 'ftp local rclone '
    assert_equal "$(job_value "$snapshots" ENGINE)" snapshot
    assert_equal "$(job_value "$snapshots" SCHEDULE)" daily:05:40
    assert_equal "$(job_value "$snapshots" TARGETS)" restic:5:7:4:-1:-1

    # BACKUP_SYSTEM lists the drivers in use; incremental backups stay on
    assert_equal "$(conf_value BACKUP_SYSTEM)" 'ftp,local,rclone'
    assert_equal "$(conf_value BACKUP_INCREMENTAL)" yes
    if [ -f "$CRONTAB" ]; then
        run grep -c 'v-backup-users' "$CRONTAB"
        assert_output 0
        run grep -c 'v-update-backup-jobs' "$CRONTAB"
        assert_output 1
    fi

    # The wrappers of earlier versions read the new state
    run v-list-backup-host-restic json
    assert_success
    assert_output --partial "\"REPO\": \"$REPO\""
}

@test "upgrade: archive records learn their job and destinations; plain restic passwords are encrypted" {
    local archives
    archives=$(job_by_name 'Nightly archives')
    run grep "^BACKUP='$U.2026-10-01_05-10-00.tar'" "$ARCPANEL/data/users/$U/backup.conf"
    assert_output --partial "JOB='$archives' DESTINATIONS='local,ftp' ENCRYPTED=''"
    run v-list-user-backups "$U" json
    assert_output --partial '"DESTINATIONS": "local,ftp"'

    assert_file_not_exists "$ARCPANEL/data/users/$U/restic.conf"
    assert_file_exists "$ARCPANEL/data/users/$U/restic/restic.age"
    assert_file_exists "$REPO/$U.password.age"
    run grep -r arcbats-plain-restic-password "$ARCPANEL/data/users/$U" "$REPO"
    assert_failure
}

@test "upgrade: a second run changes nothing" {
    local before after
    before=$(v-list-backup-destinations json | jq -S 'map_values(del(.CHECKED, .TIME, .DATE))'; v-list-backup-jobs json | jq -S 'map_values(del(.NEXT_RUN, .NEXT_RUN_TS))'; cat "$ARCPANEL/data/users/$U/backup.conf")
    [ -f "$CRONTAB" ] && before="$before$(cat "$CRONTAB")"
    run v-update-sys-backup-config
    assert_success
    refute_output --partial '[ ! ]'
    after=$(v-list-backup-destinations json | jq -S 'map_values(del(.CHECKED, .TIME, .DATE))'; v-list-backup-jobs json | jq -S 'map_values(del(.NEXT_RUN, .NEXT_RUN_TS))'; cat "$ARCPANEL/data/users/$U/backup.conf")
    [ -f "$CRONTAB" ] && after="$after$(cat "$CRONTAB")"
    assert_equal "$after" "$before"
}
