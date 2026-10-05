#!/usr/bin/env bats
# ArcPanel file manager commands (E3,
# docs/features/specs/2026-10-05-008-e3-file-manager-design.md).
# Run as root on a server installed with the ArcPanel installer. The disk
# cases mount a small tmpfs and a loop ext4 image with user quotas inside the
# test user's home.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

HESTIA=/usr/local/hestia
STAGING=$HESTIA/web-app/storage/app/files
UPLOADS=$STAGING/uploads
DOWNLOADS=$STAGING/downloads
CRONTAB=/var/spool/cron/crontabs/hestiaweb
PASS="Tf-$(date +%s)-Pass"
U1=tfu1
U2=tfu2
H1=/home/$U1
# The home directory itself belongs to root; users write below ~/tmp and ~/web
T=$H1/tmp/t
QUOTA_IMG=/root/arcpanel-files-quota.img

unmount_all() {
    umount "$H1/tmp/full" 2> /dev/null || true
    umount "$H1/tmp/quota" 2> /dev/null || true
    rm -f "$QUOTA_IMG"
}

cleanup() {
    unmount_all
    for user in $U1 $U2; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    rm -rf "/home/${U1}x"
}

setup_file() {
    cleanup
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    v-add-user $U2 "$PASS" $U2@example.invalid default > /dev/null
    v-add-sys-panel-files
}

teardown_file() {
    cleanup
    find "$UPLOADS" -mindepth 1 -maxdepth 1 -type d -empty -delete
}

setup() {
    rm -rf "$T"
    as_user mkdir -p "$T"
}

as_user() {
    setpriv --groups "$(id -G $U1 | tr ' ' ',')" --reuid $U1 --regid $U1 -- "$@"
}

token() {
    tr -dc 'a-f0-9' < /dev/urandom | head -c 32
}

# Stages CONTENT (or a file of SIZE bytes with --size) as the panel would and
# prints the staged path
stage() {
    local dir="$UPLOADS/$(token)"
    install -d -o hestiaweb -g hestiaweb -m 700 "$dir"
    if [ "$1" = "--size" ]; then
        head -c "$2" /dev/urandom > "$dir/file"
    else
        printf '%s' "$1" > "$dir/file"
    fi
    chown hestiaweb:hestiaweb "$dir/file"
    chmod 600 "$dir/file"
    echo "$dir/file"
}

@test "Setup is idempotent: staging tree and one scheduler line" {
    run v-add-sys-panel-files
    assert_success
    for dir in "$STAGING" "$UPLOADS" "$DOWNLOADS"; do
        assert_equal "$(stat -c '%U:%G %a' "$dir")" "hestiaweb:hestiaweb 700"
    done
    assert_equal "$(grep -c 'artisan schedule:run' "$CRONTAB")" "1"
    assert_equal "$(stat -c '%U %a' "$CRONTAB")" "hestiaweb 600"
}

@test "Listing: JSON, folders first, hidden, paging, sort, symlinks, odd names" {
    as_user mkdir "$T/zdir" "$T/adir"
    as_user sh -c "printf 1234567890 > '$T/big.txt'; printf 1 > '$T/small.txt'; printf x > '$T/.hidden'"
    as_user ln -s /etc/passwd "$T/out"
    as_user ln -s zdir "$T/in"
    as_user sh -c "printf x > \"$T/\$(printf 'new\nline')\""
    as_user sh -c "printf x > \"$T/\$(printf 'bad\377name')\""

    run v-list-fs-directory-json $U1 "$T"
    assert_success
    assert_equal "$(jq -r .TOTAL <<< "$output")" "9"
    assert_equal "$(jq -r '[.ENTRIES[].NAME][0:3] | join(",")' <<< "$output")" "adir,in,zdir"
    assert_equal "$(jq -r '.ENTRIES[] | select(.NAME == "out") | "\(.TYPE) \(.LINK) \(.LINK_INSIDE)"' <<< "$output")" "l /etc/passwd no"
    assert_equal "$(jq -r '.ENTRIES[] | select(.NAME == "in") | "\(.LINK_TYPE) \(.LINK_INSIDE)"' <<< "$output")" "d yes"
    assert_equal "$(jq -r '.ENTRIES[] | select(.NAME == "big.txt") | "\(.SIZE) \(.MODE) \(.OWNER)"' <<< "$output")" "10 0644 $U1"
    assert_equal "$(jq -r '[.ENTRIES[] | select(.NAME == "new\nline")] | length' <<< "$output")" "1"
    assert_equal "$(jq -r '[.ENTRIES[] | select(.VALID_NAME == "no")] | length' <<< "$output")" "1"

    run v-list-fs-directory-json $U1 "$T" 0 2000 name asc no
    assert_equal "$(jq -r .TOTAL <<< "$output")" "8"

    run v-list-fs-directory-json $U1 "$T" 3 2 size desc
    assert_equal "$(jq -r '.OFFSET, (.ENTRIES | length)' <<< "$output" | tr '\n' ' ')" "3 2 "
    # out (an 11-byte link) is the largest file, then big.txt
    assert_equal "$(jq -r '[.ENTRIES[].NAME] | join(",")' <<< "$output")" "out,big.txt"

    run v-list-fs-directory-json $U1 "$T/big.txt"
    assert_failure 3
    run v-list-fs-directory-json $U1 "$T" 0 10 color
    assert_failure 2
}

@test "Every new command refuses paths outside the home" {
    mkdir -p "/home/${U1}x"
    echo secret > "/home/${U1}x/f"
    as_user ln -s /etc "$T/etc"
    staged=$(stage hello)
    for path in "/home/$U2" "/home/$U2/missing/x" "$H1/../$U2" "/home/${U1}x/f" "$T/etc/hostname" "t/relative" "/etc/passwd"; do
        run v-list-fs-directory-json $U1 "$path"
        assert_failure 10
        run v-get-fs-file-info $U1 "$path"
        assert_failure 10
        run v-read-fs-file $U1 "$path"
        assert_failure 10
        run v-save-fs-file $U1 "$(stage x)" "$path"
        assert_failure 10
        run v-add-fs-upload $U1 "$(stage x)" "$path/new.txt"
        assert_failure 10
        run v-add-fs-download $U1 "$(token)" "$path"
        assert_failure 10
        run v-add-fs-zip $U1 "$T/a.zip" "$path"
        assert_failure 10
        run v-change-fs-directory-permission $U1 "$path" 0755
        assert_failure 10
        run v-list-fs-disk-usage $U1 "$path"
        assert_failure 10
    done
    rm -f "$staged"
}

@test "Info shows type, mode, MIME and writability as the user" {
    as_user sh -c "printf '<?php echo 1;' > '$T/i.php'; chmod 640 '$T/i.php'"
    run v-get-fs-file-info $U1 "$T/i.php" json
    assert_success
    assert_equal "$(jq -r '"\(.TYPE) \(.MODE) \(.WRITABLE) \(.SIZE)"' <<< "$output")" "f 0640 yes 13"
    assert_equal "$(jq -r .MIME <<< "$output")" "text/x-php"
    touch "$T/root.txt"
    run v-get-fs-file-info $U1 "$T/root.txt" json
    assert_equal "$(jq -r .WRITABLE <<< "$output")" "no"
    run v-get-fs-file-info $U1 "$T/missing"
    assert_failure 3
    # Below a folder that does not exist
    run v-get-fs-file-info $U1 "$T/missing/deeper/x"
    assert_failure 3
}

@test "Read prints a header with the hash, then the content; big files are refused" {
    as_user sh -c "printf 'line1\nline2\n' > '$T/r.txt'"
    run v-read-fs-file $U1 "$T/r.txt"
    assert_success
    header=$(head -n 1 <<< "$output")
    assert_equal "$(jq -r .SIZE <<< "$header")" "12"
    assert_equal "$(jq -r .HASH <<< "$header")" "$(sha256sum "$T/r.txt" | cut -d ' ' -f 1)"
    assert_equal "$(tail -n +2 <<< "$output")" "$(printf 'line1\nline2')"
    run v-read-fs-file $U1 "$T/r.txt" 5
    assert_failure 8
    run v-read-fs-file $U1 "$T"
    assert_failure 3
    chmod 600 "$T/r.txt"
    chown root:root "$T/r.txt"
    run v-read-fs-file $U1 "$T/r.txt"
    assert_failure 10
}

@test "Save: new file, hash guard, kept mode, symlink target, foreign owner in place" {
    staged=$(stage "first")
    run v-save-fs-file $U1 "$staged" "$T/s.txt"
    assert_success
    assert_file_not_exists "$staged"
    assert_equal "$(stat -c '%U %a' "$T/s.txt")" "$U1 644"
    hash=$(jq -r .HASH <<< "$output")
    assert_equal "$hash" "$(sha256sum "$T/s.txt" | cut -d ' ' -f 1)"

    as_user chmod 600 "$T/s.txt"
    run v-save-fs-file $U1 "$(stage second)" "$T/s.txt" "$hash"
    assert_success
    assert_equal "$(cat "$T/s.txt")" "second"
    assert_equal "$(stat -c '%a' "$T/s.txt")" "600"

    run v-save-fs-file $U1 "$(stage third)" "$T/s.txt" "$hash"
    assert_failure 7
    assert_equal "$(cat "$T/s.txt")" "second"
    run v-save-fs-file $U1 "$(stage third)" "$T/s.txt"
    assert_failure 4

    as_user ln -s s.txt "$T/link.txt"
    run v-save-fs-file $U1 "$(stage linked)" "$T/link.txt" "$(sha256sum "$T/s.txt" | cut -d ' ' -f 1)"
    assert_success
    assert [ -L "$T/link.txt" ]
    assert_equal "$(cat "$T/s.txt")" "linked"

    printf 'old' > "$T/shared.txt"
    chmod 666 "$T/shared.txt"
    run v-save-fs-file $U1 "$(stage new)" "$T/shared.txt" "$(sha256sum "$T/shared.txt" | cut -d ' ' -f 1)"
    assert_success
    assert_equal "$(stat -c '%U %a' "$T/shared.txt")" "root 666"
    assert_equal "$(cat "$T/shared.txt")" "new"

    chmod 644 "$T/shared.txt"
    run v-save-fs-file $U1 "$(stage x)" "$T/shared.txt" "$(sha256sum "$T/shared.txt" | cut -d ' ' -f 1)"
    assert_failure 10
}

@test "Upload: owner and mode, conflicts, staged files outside staging" {
    staged=$(stage "data")
    run v-add-fs-upload $U1 "$staged" "$T/u.txt"
    assert_success
    assert_output "$T/u.txt"
    assert_file_not_exists "$staged"
    assert_equal "$(stat -c '%U:%G %a' "$T/u.txt")" "$U1:$U1 644"

    run v-add-fs-upload $U1 "$(stage again)" "$T/u.txt"
    assert_failure 4
    run v-add-fs-upload $U1 "$(stage again)" "$T/u.txt" 0600 rename
    assert_success
    assert_output "$T/u (1).txt"
    assert_equal "$(stat -c '%a' "$T/u (1).txt")" "600"
    run v-add-fs-upload $U1 "$(stage replaced)" "$T/u.txt" 0644 overwrite
    assert_success
    assert_equal "$(cat "$T/u.txt")" "replaced"
    assert_equal "$(ls -A "$T" | grep -c arc-)" "0"

    as_user mkdir "$T/dir"
    run v-add-fs-upload $U1 "$(stage x)" "$T/dir" 0644 overwrite
    assert_failure 4

    echo nope > /tmp/arcpanel-files-staged
    run v-add-fs-upload $U1 /tmp/arcpanel-files-staged "$T/n.txt"
    assert_failure 10
    assert_file_exists /tmp/arcpanel-files-staged
    rm -f /tmp/arcpanel-files-staged
    staged=$(stage rootowned)
    chown root "$staged"
    run v-add-fs-upload $U1 "$staged" "$T/n.txt"
    assert_failure 10
    staged=$(stage x)
    ln -s "$staged" "$UPLOADS/link-$(token)"
    run v-add-fs-upload $U1 "$UPLOADS"/link-* "$T/n.txt"
    assert_failure 10
    rm -f "$UPLOADS"/link-* "$staged"

    run v-add-fs-upload $U1 "$(stage x)" "$T/missing/n.txt"
    assert_failure 3
    run v-add-fs-upload $U1 "$(stage x)" "$T/n.txt" 999
    assert_failure 2
}

@test "Upload into a full file system ends with E_DISK and leaves nothing behind" {
    as_user mkdir -p "$H1/tmp/full"
    mount -t tmpfs -o size=1m,uid="$(id -u $U1)",gid="$(id -g $U1)",mode=0755 tmpfs "$H1/tmp/full"
    staged=$(stage --size 2000000)
    run v-add-fs-upload $U1 "$staged" "$H1/tmp/full/big.bin"
    assert_failure 13
    assert_file_not_exists "$staged"
    assert_equal "$(ls -A "$H1/tmp/full" | wc -l)" "0"
    umount "$H1/tmp/full"
}

@test "Upload over the user's disk quota ends with E_DISK" {
    truncate -s 64M "$QUOTA_IMG"
    mkfs.ext4 -q -F -O quota -E quotatype=usrquota "$QUOTA_IMG"
    as_user mkdir -p "$H1/tmp/quota"
    mount -o loop,usrquota "$QUOTA_IMG" "$H1/tmp/quota"
    chown $U1:$U1 "$H1/tmp/quota"
    setquota -u $U1 1024 1024 0 0 "$H1/tmp/quota"
    staged=$(stage --size 3000000)
    run v-add-fs-upload $U1 "$staged" "$H1/tmp/quota/big.bin"
    assert_failure 13
    assert_file_not_exists "$staged"
    assert_file_not_exists "$H1/tmp/quota/big.bin"
    assert_equal "$(ls -A "$H1/tmp/quota" | grep -c arc-)" "0"

    run v-save-fs-file $U1 "$(stage --size 3000000)" "$H1/tmp/quota/saved.bin"
    assert_failure 13
    umount "$H1/tmp/quota"
    rm -f "$QUOTA_IMG"
}

@test "Download: one file copied, a folder zipped without following symlinks" {
    as_user sh -c "mkdir -p '$T/my site/ảnh'; printf 'hello' > '$T/my site/a b.txt'; printf x > '$T/my site/ảnh/x.txt'; ln -s /etc/passwd '$T/my site/out'"
    ticket=$(token)
    run v-add-fs-download $U1 "$ticket" "$T/my site/a b.txt"
    assert_success
    assert_equal "$(jq -r '"\(.NAME) \(.SIZE) \(.MIME)"' <<< "$output")" "a b.txt 5 text/plain"
    assert_equal "$(stat -c '%U %a' "$DOWNLOADS/$ticket")" "hestiaweb 600"
    assert_equal "$(cat "$DOWNLOADS/$ticket")" "hello"
    run v-add-fs-download $U1 "$ticket" "$T/my site/a b.txt"
    assert_failure 4
    rm -f "$DOWNLOADS/$ticket"

    ticket=$(token)
    run v-add-fs-download $U1 "$ticket" "$T/my site"
    assert_success
    assert_equal "$(jq -r '"\(.NAME) \(.MIME)"' <<< "$output")" "my site.zip application/zip"
    listing=$(unzip -Z1 "$DOWNLOADS/$ticket")
    assert_equal "$(grep -c 'my site/a b.txt' <<< "$listing")" "1"
    assert_equal "$(grep -c 'my site/ảnh/x.txt' <<< "$listing")" "1"
    assert_equal "$(unzip -p "$DOWNLOADS/$ticket" 'my site/out')" "/etc/passwd"
    rm -f "$DOWNLOADS/$ticket"

    as_user sh -c "printf y > '$T/other.txt'"
    run v-add-fs-download $U1 "$(token)" "$T/my site/a b.txt" "$T/other.txt"
    assert_failure 2
    run v-add-fs-download $U1 "$(token)" "$H1"
    assert_failure 10
    run v-add-fs-download $U1 "NOT-A-TICKET" "$T/other.txt"
    assert_failure 2
}

@test "Zip creates an archive owned by the user and never replaces one" {
    as_user sh -c "mkdir '$T/a dir'; printf 1 > '$T/a dir/1.txt'; printf 2 > '$T/2.txt'"
    run v-add-fs-zip $U1 "$T/pack.zip" "$T/a dir" "$T/2.txt"
    assert_success
    assert_equal "$(stat -c '%U' "$T/pack.zip")" "$U1"
    assert_equal "$(unzip -Z1 "$T/pack.zip" | sort | tr '\n' ' ')" "2.txt a dir/ a dir/1.txt "
    run v-add-fs-zip $U1 "$T/pack.zip" "$T/2.txt"
    assert_failure 4
    run v-add-fs-zip $U1 "$T/pack.tar" "$T/2.txt"
    assert_failure 2
    run v-add-fs-zip $U1 "$T/pack2.zip" "$T/a dir/1.txt"
    assert_failure 2
}

@test "Folder permissions: the folder only, or recursive with separate modes" {
    as_user sh -c "mkdir -p '$T/p/sub'; printf 1 > '$T/p/f'; printf 2 > '$T/p/sub/g'; ln -s f '$T/p/l'"
    run v-change-fs-directory-permission $U1 "$T/p" 0700
    assert_success
    assert_equal "$(stat -c '%a' "$T/p" "$T/p/sub" "$T/p/f" | tr '\n' ' ')" "700 755 644 "
    run v-change-fs-directory-permission $U1 "$T/p" 0750 0600 yes
    assert_success
    assert_equal "$(stat -c '%a' "$T/p" "$T/p/sub" "$T/p/f" "$T/p/sub/g" | tr '\n' ' ')" "750 750 600 600 "
    touch "$T/p/rootfile"
    run v-change-fs-directory-permission $U1 "$T/p" 0755 0644 yes
    assert_failure 10
    assert_equal "$(stat -c '%a' "$T/p/f")" "644"
    run v-change-fs-directory-permission $U1 "$T/p/f" 0755
    assert_failure 3
    run v-change-fs-directory-permission $U1 "$T/p" 0755 0644 maybe
    assert_failure 2
}

@test "Disk usage counts bytes as the user" {
    as_user sh -c "mkdir '$T/du'; head -c 5000 /dev/zero > '$T/du/f'"
    run v-list-fs-disk-usage $U1 "$T/du" json
    assert_success
    size=$(jq -r .SIZE <<< "$output")
    assert [ "$size" -ge 5000 ]
}
