#!/usr/bin/env bats
# ArcPanel root safety (E39 W1,
# docs/features/specs/2026-10-10-001-e39-w1-root-in-user-paths-design.md §13).
# Root commands must not work in paths the hosting user controls, must
# validate what they read from backups and must write into a home as the
# user. Run as root on a test server with restic installed. Uses a local
# restic repository below /backup when the server has none, and removes it
# again.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
RS_USER=arcbatsrs
RS_OTHER=arcbatsrs2
RS_PACKAGE=arcbats-rs-incremental
REPO_DIR=/backup/restic-arcbatsrs
STATE="$BATS_FILE_TMPDIR/restic-state"
TARGET=/root/arcbats-rs-target

setup_file() {
    local tmp pass
    v-delete-user "$RS_USER" > /dev/null 2>&1 || true
    v-delete-user "$RS_OTHER" > /dev/null 2>&1 || true
    rm -rf "$REPO_DIR/$RS_USER" "$TARGET"
    if [ -f "$ARCPANEL/conf/restic.conf" ]; then
        echo existing > "$STATE"
    else
        echo created > "$STATE"
        mkdir -p "$REPO_DIR"
        v-add-backup-host-restic "$REPO_DIR" 5 -1 -1 -1 -1 > /dev/null
    fi
    tmp=$(mktemp)
    sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$tmp"
    grep -q "^BACKUPS_INCREMENTAL=" "$tmp" || echo "BACKUPS_INCREMENTAL='yes'" >> "$tmp"
    v-add-user-package "$tmp" "$RS_PACKAGE" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$RS_PACKAGE" > /dev/null
    rm -f "$tmp"
    pass="Rs-$(date +%s)-Pass"
    v-add-user "$RS_USER" "$pass" "$RS_USER@example.invalid" "$RS_PACKAGE" > /dev/null
    v-add-user "$RS_OTHER" "$pass" "$RS_OTHER@example.invalid" default > /dev/null
    runuser -u "$RS_USER" -- sh -c "mkdir -p '/home/$RS_USER/tmp/notes' '/home/$RS_USER/tmp/my notes' \
        && echo original > '/home/$RS_USER/tmp/notes/a.txt' && echo spaced > '/home/$RS_USER/tmp/my notes/b.txt'"
    v-backup-user-restic "$RS_USER" > /dev/null 2>&1
}

teardown_file() {
    v-delete-user "$RS_USER" > /dev/null 2>&1 || true
    v-delete-user "$RS_OTHER" > /dev/null 2>&1 || true
    v-delete-user-package "$RS_PACKAGE" > /dev/null 2>&1 || true
    rm -rf "$TARGET"
    if [ "$(cat "$STATE" 2> /dev/null)" = created ]; then
        v-delete-backup-host-restic > /dev/null 2>&1 || rm -f "$ARCPANEL/conf/restic.conf"
        v-change-sys-config-value BACKUP_INCREMENTAL no > /dev/null 2>&1 || true
        rm -rf "$REPO_DIR"
    fi
}

snapshot_id() {
    v-list-user-backups-restic "$RS_USER" json | jq -r '.[0].short_id'
}

# One SQL statement on the local server, results without headers
sql() {
    if command -v mariadb > /dev/null; then
        mariadb -N -e "$1" 2> /dev/null
    else
        mysql -N -e "$1" 2> /dev/null
    fi
}

# Runs a snippet with the libraries of the commands loaded
lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/safe-fs.sh; user=$RS_USER; $1"
}

#----------------------------------------------------------#
#                 M1: library and arguments                #
#----------------------------------------------------------#

@test "safe_workdir creates a root-only folder below .arcpanel-work and removes it on exit" {
    run lib 'safe_workdir test; stat -c "%u %a" "$SAFE_WORKDIR" "$(dirname "$SAFE_WORKDIR")"; echo "$SAFE_WORKDIR"'
    assert_success
    assert_line --index 0 '0 700'
    assert_line --index 1 '0 700'
    [[ "${lines[2]}" == /backup/.arcpanel-work/test.* ]]
    assert_not_exist "${lines[2]}"
}

@test "safe_workdir refuses a .arcpanel-work that is a link or not root's" {
    local saved=/backup/.arcpanel-work.bats-saved
    mv /backup/.arcpanel-work "$saved" 2> /dev/null || true
    mkdir -p "$TARGET"
    ln -s "$TARGET" /backup/.arcpanel-work
    run lib 'safe_workdir test'
    rm -f /backup/.arcpanel-work
    assert_failure 10
    mkdir -m 700 /backup/.arcpanel-work
    chown "$RS_USER" /backup/.arcpanel-work
    run lib 'safe_workdir test'
    rm -rf /backup/.arcpanel-work
    [ -d "$saved" ] && mv "$saved" /backup/.arcpanel-work
    assert_failure 10
    run find "$TARGET" -mindepth 1
    assert_output ''
}

@test "is_restore_name_valid refuses traversal, options and control characters" {
    run lib 'for n in example.com sub.example.com; do is_restore_name_valid domain "$n" || echo "bad $n"; done'
    assert_output ''
    run lib 'for n in ../x -x "a/b" "." "a..b" "ex ample.com" "x'"'"'y" $'"'"'a\nb'"'"'; do is_restore_name_valid domain "$n" && echo "passed $n"; done; true'
    assert_output ''
    run lib 'is_restore_name_valid database "arcbatsrs_db" && is_restore_name_valid segment "my notes" && echo ok'
    assert_output ok
}

@test "parse_backup_record sets allow-listed keys only and refuses quotes and new lines" {
    run lib "parse_backup_record 'DOMAIN IP' \"DOMAIN='a.example' IP='1.2.3.4' USER_DATA='/etc' PATH='/arcbats-path'\" && echo \"\$DOMAIN \$IP \$USER_DATA \$PATH\""
    refute_output --partial '/etc'
    refute_output --partial '/arcbats-path'
    assert_output --partial 'a.example 1.2.3.4 /usr/local/arcpanel/data/users/'
    # A key in lower case would name a variable of the script: the record is refused
    run lib "tmpdir=/keep; parse_backup_record 'DOMAIN' \"DOMAIN='a.example' tmpdir='/etc'\" || echo \"refused \$tmpdir\""
    assert_output 'refused /keep'
    run lib "parse_backup_record 'DOMAIN' \"DOMAIN='a\\\$(id)'\" || echo refused"
    assert_output refused
    run lib "parse_backup_record 'DOMAIN' \$'DOMAIN=\\'a\\'\\nIP=\\'1\\'' || echo refused"
    assert_output refused
    run lib "parse_backup_record 'CMD' \"CMD='echo \\\$HOME > /dev/null'\" && echo \"\$CMD\""
    assert_output 'echo $HOME > /dev/null'
}

@test "restic executors refuse a snapshot that is not an ID" {
    local snapshot
    for snapshot in --help '-x' 'abc|x' 'latest x' 0123ABCD; do
        run v-list-user-files-restic "$RS_USER" "$snapshot" "/home/$RS_USER"
        assert_failure 2
        run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
        assert_failure 2
        run v-delete-user-backup-restic "$RS_USER" "$snapshot"
        assert_failure 2
    done
}

@test "v-list-user-files-restic lists a folder with spaces and refuses folders outside the home" {
    local snapshot
    snapshot=$(snapshot_id)
    run v-list-user-files-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/my notes"
    assert_success
    assert_output --partial '"path":"/home/arcbatsrs/tmp/my notes/b.txt"'
    for folder in /etc "/home/$RS_OTHER" "/home/${RS_USER}x" "/home/$RS_USER/../$RS_OTHER" "/home/$RS_USER/./tmp" "/home/$RS_USER//tmp" tmp; do
        run v-list-user-files-restic "$RS_USER" "$snapshot" "$folder"
        assert_failure 10
    done
}

@test "v-restore-file-restic refuses paths outside the home" {
    local snapshot
    snapshot=$(snapshot_id)
    for path in /etc/passwd "/home/$RS_USER" "/home/$RS_USER/../$RS_OTHER/x" "/home/${RS_USER}x/a" "/home/$RS_USER/./tmp" notes/a.txt; do
        run v-restore-file-restic "$RS_USER" "$snapshot" "$path"
        assert_failure 10
    done
}

@test "v-restore-file-restic restores as the user, quoted, and never through a link" {
    local snapshot
    snapshot=$(snapshot_id)
    runuser -u "$RS_USER" -- sh -c "echo changed > '/home/$RS_USER/tmp/notes/a.txt'; rm -rf '/home/$RS_USER/tmp/my notes'"
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
    assert_success
    assert_equal "$(cat "/home/$RS_USER/tmp/notes/a.txt")" original
    assert_equal "$(stat -c %U "/home/$RS_USER/tmp/notes/a.txt")" "$RS_USER"
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/my notes"
    assert_success
    assert_equal "$(cat "/home/$RS_USER/tmp/my notes/b.txt")" spaced
    assert_equal "$(stat -c %U "/home/$RS_USER/tmp/my notes")" "$RS_USER"

    # The folder becomes a link to a root folder: nothing is written there
    mkdir -p "$TARGET"
    runuser -u "$RS_USER" -- sh -c "rm -rf '/home/$RS_USER/tmp/notes'"
    ln -s "$TARGET" "/home/$RS_USER/tmp/notes"
    chown -h "$RS_USER:$RS_USER" "/home/$RS_USER/tmp/notes"
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
    assert_failure
    assert_not_exist "$TARGET/a.txt"
    rm -f "/home/$RS_USER/tmp/notes"
    run find /backup/.arcpanel-work -mindepth 1
    assert_output ''
}

@test "v-restore-file-restic honours demo mode" {
    local snapshot
    snapshot=$(snapshot_id)
    v-change-sys-config-value DEMO_MODE yes
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
    sed -i "s/^DEMO_MODE='yes'/DEMO_MODE='no'/" "$ARCPANEL/conf/arcpanel.conf"
    assert_failure 1
    assert_output --partial 'security restrictions'
}

#----------------------------------------------------------#
#                 M2: restic restores                      #
#----------------------------------------------------------#

RS_WEB=rs-web.arcbats.example
RS_DNS=rs-dns.arcbats.example
RS_MAIL=rs-mail.arcbats.example

# The objects of the M2 cases, created once
m2_objects() {
    [ -f "$BATS_FILE_TMPDIR/m2-objects" ] && return 0
    v-add-web-domain "$RS_USER" "$RS_WEB" > /dev/null
    v-add-dns-domain "$RS_USER" "$RS_DNS" 192.0.2.10 > /dev/null
    v-add-mail-domain "$RS_USER" "$RS_MAIL" > /dev/null
    v-add-mail-account "$RS_USER" "$RS_MAIL" info "Rs-$(date +%s)-Mail" > /dev/null
    v-add-database "$RS_USER" rsdb rsdb "Rs-$(date +%s)-Db" mysql > /dev/null
    sql "CREATE TABLE ${RS_USER}_rsdb.notes (v VARCHAR(20)); INSERT INTO ${RS_USER}_rsdb.notes VALUES ('original');"
    runuser -u "$RS_USER" -- sh -c "echo site > '/home/$RS_USER/web/$RS_WEB/public_html/site.txt'"
    touch "$BATS_FILE_TMPDIR/m2-objects"
}

# Stages the configuration, lets CHANGE (a function name) edit ~/backup as
# an attacker would, then takes the snapshot and prints its short ID
crafted_snapshot() {
    local repo
    v-backup-user-config "$RS_USER" > /dev/null
    [ -n "$1" ] && "$1"
    repo=$(. "$ARCPANEL/conf/restic.conf"; echo "${REPO%/}")
    restic --repo "$repo/$RS_USER" --password-file "$ARCPANEL/data/users/$RS_USER/restic.conf" \
        backup "/home/$RS_USER" --json 2> /dev/null | jq -r 'select(.message_type == "summary") | .snapshot_id' | cut -c 1-8
}

@test "v-backup-user-config stages in root's folder and leaves ~/backup to the user" {
    m2_objects
    rm -rf "/home/$RS_USER/backup"
    run v-backup-user-config "$RS_USER"
    assert_success
    assert_equal "$(stat -c '%U %a' "/home/$RS_USER/backup")" "$RS_USER 700"
    assert_file_exist "/home/$RS_USER/backup/backup.conf"
    run find "/home/$RS_USER/backup" ! -user "$RS_USER"
    assert_output ''
    run find /backup/.arcpanel-work -mindepth 1
    assert_output ''
}

@test "restic restores bring back web files, DNS records, mail accounts and databases" {
    local snapshot
    m2_objects
    snapshot=$(crafted_snapshot)
    [ -n "$snapshot" ]
    runuser -u "$RS_USER" -- rm -f "/home/$RS_USER/web/$RS_WEB/public_html/site.txt"
    v-delete-dns-domain "$RS_USER" "$RS_DNS" > /dev/null
    v-delete-mail-account "$RS_USER" "$RS_MAIL" info > /dev/null
    sql "DELETE FROM ${RS_USER}_rsdb.notes"

    run v-restore-web-domain-restic "$RS_USER" "$snapshot" "$RS_WEB"
    assert_success
    assert_equal "$(cat "/home/$RS_USER/web/$RS_WEB/public_html/site.txt")" site
    assert_equal "$(stat -c %U "/home/$RS_USER/web/$RS_WEB/public_html/site.txt")" "$RS_USER"
    assert_equal "$(stat -c %G "/home/$RS_USER/web/$RS_WEB/public_html")" www-data

    run v-restore-dns-domain-restic "$RS_USER" "$snapshot" "$RS_DNS"
    assert_success
    run v-list-dns-records "$RS_USER" "$RS_DNS" json
    assert_success
    assert_output --partial '_imap._tcp'

    run v-restore-mail-domain-restic "$RS_USER" "$snapshot" "$RS_MAIL"
    assert_success
    run v-list-mail-account "$RS_USER" "$RS_MAIL" info
    assert_success

    run v-restore-database-restic "$RS_USER" "$snapshot" "${RS_USER}_rsdb"
    assert_success
    assert_equal "$(sql "SELECT v FROM ${RS_USER}_rsdb.notes")" original
    run sql "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'arcrestore%'"
    assert_output 0
    run find /backup/.arcpanel-work -mindepth 1
    assert_output ''
}

evil_index() {
    sed -i "s/^\(.*\) DNS='\([^']*\)'/\1 DNS='\2,..\/evil,-x'/" "/home/$RS_USER/backup/backup.conf"
}

@test "names from a snapshot that are not valid are skipped" {
    local snapshot
    m2_objects
    snapshot=$(crafted_snapshot evil_index)
    run v-restore-dns-domain-restic "$RS_USER" "$snapshot" '*'
    assert_success
    assert_output --partial 'Skipped DNS domain ../evil: invalid name'
    assert_output --partial 'Skipped DNS domain -x: invalid name'
    assert_not_exist "$ARCPANEL/data/users/evil.conf"
}

lowercase_index() {
    sed -i "s/^/tmpdir='\/etc' /" "/home/$RS_USER/backup/backup.conf"
}

@test "a backup.conf that sets a variable of the script is refused" {
    local snapshot
    m2_objects
    snapshot=$(crafted_snapshot lowercase_index)
    run v-restore-dns-domain-restic "$RS_USER" "$snapshot" '*'
    assert_failure 12
    assert_dir_exist /etc/cron.d
}

evil_records() {
    local b="/home/$RS_USER/backup"
    sed -i "s/^DOMAIN=/EVIL='x' DOMAIN=/" "$b/mail/$RS_MAIL/arcpanel/mail.conf"
    ln -sf /etc/shadow "$b/mail/$RS_MAIL/arcpanel/$RS_MAIL.pem"
    sed -i "s/ IP='[^']*'/ IP='1.1.1.1\/;e touch \/root\/arcbats-sed;s\/x'/" "$b/dns/$RS_DNS/arcpanel/dns.conf"
    sed -i "s/ FTP_USER='[^']*'/ FTP_USER='${RS_USER}_ftp'/; s/ FTP_MD5='[^']*'/ FTP_MD5='x:|;s|^root:[^:]*:|root:PWNED:|;s|zz'/" \
        "$b/web/$RS_WEB/arcpanel/web.conf"
    printf 'CREATE DATABASE arcbats_evil;\n' | zstd -q -f -o "$b/db/${RS_USER}_rsdb/${RS_USER}_rsdb.mysql.sql.zst"
    printf 'CREATE DATABASE arcbats_evil;\n' | gzip > "$b/db/${RS_USER}_rsdb/${RS_USER}_rsdb.mysql.sql.gz"
}

@test "records, links and dumps from a snapshot cannot reach root or other tenants" {
    local snapshot shadow
    m2_objects
    shadow=$(grep '^root:' /etc/shadow)
    snapshot=$(crafted_snapshot evil_records)
    v-delete-mail-domain "$RS_USER" "$RS_MAIL" > /dev/null
    v-delete-dns-domain "$RS_USER" "$RS_DNS" > /dev/null
    v-delete-web-domain "$RS_USER" "$RS_WEB" > /dev/null

    run v-restore-mail-domain-restic "$RS_USER" "$snapshot" "$RS_MAIL"
    assert_success
    run grep -c EVIL "$ARCPANEL/data/users/$RS_USER/mail.conf"
    assert_output 0
    run grep -qs 'root:' "$ARCPANEL/data/users/$RS_USER/mail/$RS_MAIL.pem"
    assert_failure

    run v-restore-dns-domain-restic "$RS_USER" "$snapshot" "$RS_DNS"
    assert_not_exist /root/arcbats-sed

    run v-restore-web-domain-restic "$RS_USER" "$snapshot" "$RS_WEB"
    assert_output --partial "Skipped web domain $RS_WEB: invalid record"
    assert_equal "$(grep '^root:' /etc/shadow)" "$shadow"

    run v-restore-database-restic "$RS_USER" "$snapshot" "${RS_USER}_rsdb"
    run sql "SHOW DATABASES LIKE 'arcbats_evil'"
    assert_output ''
    run sql "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'arcrestore%'"
    assert_output 0
}

@test "restic restores do not write through a link in ~/tmp" {
    local snapshot
    m2_objects
    snapshot=$(crafted_snapshot)
    mkdir -p "$TARGET"
    mv "/home/$RS_USER/tmp" "/home/$RS_USER/tmp.saved"
    ln -s "$TARGET" "/home/$RS_USER/tmp"
    run v-list-user-backup-restic "$RS_USER" "$snapshot" json
    run v-restore-cron-job-restic "$RS_USER" "$snapshot"
    run v-restore-dns-domain-restic "$RS_USER" "$snapshot" "$RS_DNS"
    rm -f "/home/$RS_USER/tmp"
    mv "/home/$RS_USER/tmp.saved" "/home/$RS_USER/tmp"
    run find "$TARGET" -mindepth 1
    assert_output ''
}

@test "v-list-user-backup-restic prints the snapshot index" {
    local snapshot
    m2_objects
    snapshot=$(crafted_snapshot)
    run v-list-user-backup-restic "$RS_USER" "$snapshot" json
    assert_success
    run jq -r '.snapshot.DNS' <<< "$output"
    assert_output --partial "$RS_DNS"
}

#----------------------------------------------------------#
#        M3: tar restore, import and SSL folder            #
#----------------------------------------------------------#

# The newest tar archive of the test user, made once
rs_archive() {
    if ! ls /backup/"$RS_USER".*.tar > /dev/null 2>&1; then
        v-backup-user "$RS_USER" > /dev/null 2>&1
    fi
    basename "$(ls -t /backup/"$RS_USER".*.tar | head -n 1)"
}

@test "v-backup-user and v-restore-user keep the backup folder at 0711" {
    local archive
    chmod 755 /backup
    archive=$(rs_archive)
    v-backup-user "$RS_USER" > /dev/null 2>&1
    assert_equal "$(stat -c %a /backup)" 711
    chmod 755 /backup
    run v-restore-user "$RS_USER" "$archive" no no no no no no
    assert_equal "$(stat -c %a /backup)" 711
}

@test "v-restore-user unpacks user folders as the user and keeps the home's owner" {
    local archive owner
    # ~/tmp is not in tar backups; a folder of the user at the top of the home is
    mkdir -p "/home/$RS_USER/arcbats-files"
    chown "$RS_USER:$RS_USER" "/home/$RS_USER/arcbats-files"
    runuser -u "$RS_USER" -- sh -c "echo tar-original > '/home/$RS_USER/arcbats-files/t.txt'"
    rm -f /backup/"$RS_USER".*.tar
    archive=$(rs_archive)
    owner=$(stat -c '%U %a' "/home/$RS_USER")
    runuser -u "$RS_USER" -- sh -c "echo changed > '/home/$RS_USER/arcbats-files/t.txt'"
    run v-restore-user "$RS_USER" "$archive" no no no no no '*'
    assert_success
    assert_equal "$(cat "/home/$RS_USER/arcbats-files/t.txt")" tar-original
    assert_equal "$(stat -c '%U %a' "/home/$RS_USER")" "$owner"

    # A folder missing from the home is created for the user
    rm -rf "/home/$RS_USER/arcbats-files"
    run v-restore-user "$RS_USER" "$archive" no no no no no arcbats-files
    assert_success
    assert_equal "$(stat -c %U "/home/$RS_USER/arcbats-files/t.txt")" "$RS_USER"
    assert_equal "$(stat -c %U "/home/$RS_USER/arcbats-files")" "$RS_USER"
    assert_equal "$(stat -c '%U %a' "/home/$RS_USER")" "$owner"
    run find /backup/.arcpanel-work -mindepth 1
    assert_output ''
}

@test "v-restore-user skips user folders whose names are not valid" {
    local archive work
    archive=$(rs_archive)
    work=$(mktemp -d)
    cp "/backup/$archive" /backup/"$RS_USER".crafted.tar
    mkdir -p "$work/user_dir"
    tar -C "$work" -cf - --files-from /dev/null | zstd -q -o "$work/user_dir/-rf.tar.zst"
    tar -rf /backup/"$RS_USER".crafted.tar -C "$work" ./user_dir/-rf.tar.zst
    run v-restore-user "$RS_USER" "$RS_USER".crafted.tar no no no no no '*'
    rm -rf "$work" /backup/"$RS_USER".crafted.tar
    assert_output --partial 'Skipped user folder -rf: invalid name'
}

@test "v-change-web-domain-sslcert refuses linked files and hands the old certificate to the user" {
    local dir domain=rs-ssl.arcbats.example
    v-add-web-domain "$RS_USER" "$domain" > /dev/null
    dir=$(mktemp -d)
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$domain" \
        -keyout "$dir/$domain.key" -out "$dir/$domain.crt" > /dev/null 2>&1
    run v-add-web-domain-ssl "$RS_USER" "$domain" "$dir"
    assert_success
    openssl req -x509 -newkey rsa:2048 -nodes -days 3 -subj "/CN=$domain" \
        -keyout "$dir/$domain.key" -out "$dir/$domain.crt" > /dev/null 2>&1
    ln -sf /etc/shadow "$dir/$domain.ca"
    run v-change-web-domain-sslcert "$RS_USER" "$domain" "$dir"
    assert_failure 10
    rm -f "$dir/$domain.ca"
    run v-change-web-domain-sslcert "$RS_USER" "$domain" "$dir"
    assert_success
    run find "/home/$RS_USER/web/$domain/private" -maxdepth 1 -name 'ssl-previous-*' -user "$RS_USER"
    refute_output ''
    run find "/home/$RS_USER/web/$domain/private" ! -user "$RS_USER"
    assert_output ''
    rm -rf "$dir"
}

#----------------------------------------------------------#
#                     M4: SSH keys                         #
#----------------------------------------------------------#

# A new public key line with COMMENT
new_key() {
    local dir
    dir=$(mktemp -d)
    ssh-keygen -q -t ed25519 -N '' -C "$1" -f "$dir/k"
    cat "$dir/k.pub"
    rm -rf "$dir"
}

@test "SSH keys are added and deleted as the user" {
    local key
    key=$(new_key arcbats-one)
    run v-add-user-ssh-key "$RS_USER" "$key"
    assert_success
    assert_equal "$(stat -c %U "/home/$RS_USER/.ssh/authorized_keys")" "$RS_USER"
    run grep -c arcbats-one "/home/$RS_USER/.ssh/authorized_keys"
    assert_output 1
    run v-delete-user-ssh-key "$RS_USER" arcbats-one
    assert_success
    run grep -c arcbats-one "/home/$RS_USER/.ssh/authorized_keys"
    assert_output 0
    assert_equal "$(stat -c %U "/home/$RS_USER/.ssh/authorized_keys")" "$RS_USER"
    run v-add-user-ssh-key "$RS_USER" "$key"$'\n'"$key"
    assert_failure 2
}

@test "a link in place of ~/.ssh or authorized_keys never reaches the target" {
    local key before
    key=$(new_key arcbats-link)
    mkdir -p "$TARGET/.ssh"
    echo 'ssh-ed25519 AAAA target-key' > "$TARGET/.ssh/authorized_keys"
    before=$(sha256sum "$TARGET/.ssh/authorized_keys")
    mv "/home/$RS_USER/.ssh" "/home/$RS_USER/.ssh.saved"
    ln -s "$TARGET/.ssh" "/home/$RS_USER/.ssh"
    run v-add-user-ssh-key "$RS_USER" "$key"
    assert_failure
    run v-delete-user-ssh-key "$RS_USER" target-key
    assert_failure
    rm -f "/home/$RS_USER/.ssh"
    mv "/home/$RS_USER/.ssh.saved" "/home/$RS_USER/.ssh"

    runuser -u "$RS_USER" -- rm -f "/home/$RS_USER/.ssh/authorized_keys"
    ln -s "/home/$RS_OTHER/.ssh/authorized_keys" "/home/$RS_USER/.ssh/authorized_keys"
    chown -h "$RS_USER:$RS_USER" "/home/$RS_USER/.ssh/authorized_keys"
    run v-add-user-ssh-key "$RS_USER" "$key"
    assert_failure
    rm -f "/home/$RS_USER/.ssh/authorized_keys"
    assert_equal "$(sha256sum "$TARGET/.ssh/authorized_keys")" "$before"
    run grep -qs arcbats-link "/home/$RS_OTHER/.ssh/authorized_keys"
    assert_failure
}

@test "the legacy file manager key is gone, and v-update-sys-user-homes removes old copies" {
    assert_not_exist "$ARCPANEL/bin/v-add-user-sftp-key"
    runuser -u "$RS_USER" -- sh -c "touch '/home/$RS_USER/.ssh/arcpanel-filemanager-key' && echo 'ssh-rsa AAAA x filemanager.ssh.key' >> '/home/$RS_USER/.ssh/authorized_keys' && echo 'ssh-ed25519 AAAA keep' >> '/home/$RS_USER/.ssh/authorized_keys'"
    run v-update-sys-user-homes "$RS_USER"
    assert_success
    assert_not_exist "/home/$RS_USER/.ssh/arcpanel-filemanager-key"
    run grep -c filemanager.ssh.key "/home/$RS_USER/.ssh/authorized_keys"
    assert_output 0
    run grep -c keep "/home/$RS_USER/.ssh/authorized_keys"
    assert_output 1
    assert_equal "$(stat -c %U "/home/$RS_USER/.ssh/authorized_keys")" "$RS_USER"
}

#----------------------------------------------------------#
#     M5: build folders, web root and smaller items        #
#----------------------------------------------------------#

@test "the build script refuses a build folder that is not root's alone" {
    local script="$BATS_TEST_DIRNAME/../src/arcpanel-build.sh"
    [ -f "$script" ] || skip "no build script next to the suite"
    rm -rf /tmp/arcbats-build /tmp/arcbats-build-link
    install -d -m 700 -o "$RS_USER" /tmp/arcbats-build
    run env TERM=dumb BUILD_DIR=/tmp/arcbats-build bash "$script" --arcpanel --noinstall --keepbuild '~localsrc'
    assert_failure
    assert_output --partial 'must belong to root with mode 0700'
    rm -rf /tmp/arcbats-build
    ln -s /root /tmp/arcbats-build-link
    run env TERM=dumb BUILD_DIR=/tmp/arcbats-build-link bash "$script" --arcpanel --noinstall --keepbuild '~localsrc'
    assert_failure
    assert_output --partial 'must belong to root with mode 0700'
    rm -f /tmp/arcbats-build-link
}

@test "a web domain's logs folder that is a link gets no links from root" {
    local domain=rs-logs.arcbats.example
    v-add-web-domain "$RS_USER" "$domain" > /dev/null
    assert_link_exist "/home/$RS_USER/web/$domain/logs/$domain.log"
    assert_equal "$(stat -c %U "/home/$RS_USER/web/$domain/logs/$domain.log")" "$RS_USER"
    mkdir -p "$TARGET/logs"
    rm -rf "/home/$RS_USER/web/$domain/logs"
    ln -s "$TARGET/logs" "/home/$RS_USER/web/$domain/logs"
    run v-rebuild-web-domain "$RS_USER" "$domain"
    run find "$TARGET/logs" -mindepth 1
    assert_output ''
    rm -f "/home/$RS_USER/web/$domain/logs"
    v-delete-web-domain "$RS_USER" "$domain" > /dev/null 2>&1
}

@test "v-add-web-domain leaves the skeleton to the user" {
    local domain=rs-skel.arcbats.example
    v-add-web-domain "$RS_USER" "$domain" > /dev/null
    run find "/home/$RS_USER/web/$domain/public_html" ! -user "$RS_USER"
    assert_output ''
    run grep -rl '%domain%' "/home/$RS_USER/web/$domain/public_html"
    assert_output ''
    v-delete-web-domain "$RS_USER" "$domain" > /dev/null 2>&1
}

@test "v-run-cli-cmd passes arguments literally, without globs or splitting" {
    run v-run-cli-cmd "$RS_USER" ls -d "/home/$RS_USER/tm*"
    assert_failure
    assert_output --partial 'cannot access'
    run v-run-cli-cmd "$RS_USER" mkdir "/home/$RS_USER/tmp/a b"
    assert_success
    assert_dir_exist "/home/$RS_USER/tmp/a b"
    assert_not_exist "/home/$RS_USER/tmp/a"
}

@test "password files are read only when root or the panel wrote them" {
    printf 'from-root\n' > /tmp/arcbats-pw-root
    printf 'from-user\n' > /tmp/arcbats-pw-user
    chown "$RS_USER" /tmp/arcbats-pw-user
    ln -sf /tmp/arcbats-pw-root /tmp/arcbats-pw-link
    run lib 'password=/tmp/arcbats-pw-root; is_password_valid; echo "$password"'
    assert_output from-root
    run lib 'password=/tmp/arcbats-pw-user; is_password_valid; echo "$password"'
    assert_output /tmp/arcbats-pw-user
    run lib 'hash=/tmp/arcbats-pw-link; is_hash_valid; echo "$hash"'
    assert_output /tmp/arcbats-pw-link
    run lib 'password=/tmp/../tmp/arcbats-pw-root; is_password_valid; echo "$password"'
    assert_output /tmp/../tmp/arcbats-pw-root
    rm -f /tmp/arcbats-pw-root /tmp/arcbats-pw-user /tmp/arcbats-pw-link
}

@test "user processes get no descriptor of root, and logs of user output are capped" {
    run lib 'exec 7> /tmp/arcbats-fd; user_exec ls /proc/self/fd'
    rm -f /tmp/arcbats-fd
    assert_success
    refute_line 7
    run lib 'yes arcbats | head -c 100000 | log_cap 1000 | wc -c'
    [ "$output" -lt 1100 ]
    run lib 'yes arcbats | head -c 100000 | log_cap 1000 | tail -n 1'
    assert_output '[output truncated]'
}
