#!/usr/bin/env bats
# ArcPanel file manager commands (E3,
# docs/features/specs/2026-10-05-008-e3-file-manager-design.md) and the path
# checks and extract formats of the inherited file commands (D52, D53 of
# docs/features/specs/2026-10-08-003-e34-api-v2-batch5-design.md).
# Run as root on a server installed with the ArcPanel installer. The disk
# cases mount a small tmpfs and a loop ext4 image with user quotas inside the
# test user's home. The 7z case needs 7-Zip (7z, 7za or 7zz).

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
STAGING=$ARCPANEL/web-app/storage/app/files
UPLOADS=$STAGING/uploads
DOWNLOADS=$STAGING/downloads
CRONTAB=/var/spool/cron/crontabs/arcpanelweb
PASS="Tf-$(date +%s)-Pass"
U1=tfu1
U2=tfu2
# A user whose name extends U1: /home/tfu1x starts with /home/tfu1
U3=${U1}x
H1=/home/$U1
# Files outside the home of U1 that U1 owns (D53)
OUT_TMP=/tmp/tfd53
OUT_SIBLING=/home/$U3/tfd53
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
    for user in $U1 $U2 $U3; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    rm -rf "/home/${U1}x" "$OUT_TMP"*
}

setup_file() {
    cleanup
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    v-add-user $U2 "$PASS" $U2@example.invalid default > /dev/null
    v-add-user $U3 "$PASS" $U3@example.invalid default > /dev/null
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
    install -d -o arcpanelweb -g arcpanelweb -m 700 "$dir"
    if [ "$1" = "--size" ]; then
        head -c "$2" /dev/urandom > "$dir/file"
    else
        printf '%s' "$1" > "$dir/file"
    fi
    chown arcpanelweb:arcpanelweb "$dir/file"
    chmod 600 "$dir/file"
    echo "$dir/file"
}

@test "Setup is idempotent: staging tree and one scheduler line" {
    run v-add-sys-panel-files
    assert_success
    for dir in "$STAGING" "$UPLOADS" "$DOWNLOADS"; do
        assert_equal "$(stat -c '%U:%G %a' "$dir")" "arcpanelweb:arcpanelweb 700"
    done
    assert_equal "$(grep -c 'artisan schedule:run' "$CRONTAB")" "1"
    assert_equal "$(stat -c '%U %a' "$CRONTAB")" "arcpanelweb 600"
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
    assert_equal "$(stat -c '%U %a' "$DOWNLOADS/$ticket")" "arcpanelweb 600"
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

# Inherited file commands (E34 batch 5, D53)

# Runs every inherited file command with a path below PREFIX, which resolves
# outside the home of U1, as source and as destination; each one must end
# with E_FORBIDEN. $T holds f.txt, d/ and a.zip of U1.
refuses_outside() {
    local p=$1
    run v-add-fs-archive $U1 "$T/new.tar" "$p.txt"
    assert_failure 10
    run v-add-fs-archive $U1 "$p-new.tar" "$T/f.txt"
    assert_failure 10
    run v-add-fs-directory $U1 "$p-dir/sub"
    assert_failure 10
    run v-add-fs-file $U1 "$p-new.txt"
    assert_failure 10
    run v-change-fs-file-permission $U1 "$p.txt" 0600
    assert_failure 10
    run v-check-fs-permission $U1 "$p.txt"
    assert_failure 10
    run v-copy-fs-file $U1 "$p.txt" "$T/copy.txt"
    assert_failure 10
    run v-copy-fs-file $U1 "$T/f.txt" "$p-copy.txt"
    assert_failure 10
    run v-copy-fs-directory $U1 "$p.d" "$T/copy.d"
    assert_failure 10
    run v-copy-fs-directory $U1 "$T/d" "$p-copy.d"
    assert_failure 10
    run v-move-fs-file $U1 "$p.txt" "$T/moved.txt"
    assert_failure 10
    run v-move-fs-file $U1 "$T/f.txt" "$p-moved.txt"
    assert_failure 10
    run v-move-fs-directory $U1 "$p.d" "$T/moved.d"
    assert_failure 10
    run v-move-fs-directory $U1 "$T/d" "$p-moved.d"
    assert_failure 10
    run v-delete-fs-file $U1 "$p.txt"
    assert_failure 10
    run v-delete-fs-directory $U1 "$p.d"
    assert_failure 10
    run v-extract-fs-archive $U1 "$p.zip" "$T/x"
    assert_failure 10
    run v-extract-fs-archive $U1 "$T/a.zip" "$p-x"
    assert_failure 10
    run v-open-fs-file $U1 "$p.txt"
    assert_failure 10
    refute_output --partial 'outside secret'
}

@test "The inherited file commands refuse /tmp, a home sharing the prefix, .. and links that leave the home" {
    as_user sh -c "printf mine > '$T/f.txt'; mkdir '$T/d'; cd '$T' && zip -q a.zip f.txt"
    as_user ln -s /etc "$T/etc"
    as_user ln -s "/home/$U3" "$T/sibling"
    # Owned by U1, so only the path check can stop the commands
    for p in "$OUT_TMP" "$OUT_SIBLING"; do
        printf 'outside secret' > "$p.txt"
        mkdir -p "$p.d"
        zip -qj "$p.zip" "$p.txt"
        chown -R $U1:$U1 "$p.txt" "$p.d" "$p.zip"
        chmod 644 "$p.txt"
    done

    for p in "$OUT_TMP" "$OUT_SIBLING" "$H1/../$U3/tfd53" "$T/sibling/tfd53" "$T/etc/tfd53"; do
        refuses_outside "$p"
    done
    run v-delete-fs-directory $U1 "$T/etc"
    assert_failure 10
    run v-change-fs-file-permission $U1 "$T/etc/hostname" 0777
    assert_failure 10
    run v-open-fs-file $U1 "$T/etc/hostname"
    assert_failure 10
    run v-add-fs-file $U1 "relative.txt"
    assert_failure 10

    for p in "$OUT_TMP" "$OUT_SIBLING"; do
        assert_equal "$(stat -c '%U %a' "$p.txt")" "$U1 644"
        assert_equal "$(cat "$p.txt")" "outside secret"
        assert_dir_exists "$p.d"
        assert_equal "$(ls -d "$p"-* 2> /dev/null | wc -l)" "0"
    done
    assert_equal "$(ls -A "$T" | sort | tr '\n' ' ')" "a.zip d etc f.txt sibling "
    assert_equal "$(cat "$T/f.txt")" "mine"
}

@test "The inherited file commands work inside the home and protect the home itself" {
    run v-add-fs-directory $U1 "$T/n1/n2/n3"
    assert_success
    assert_equal "$(stat -c '%U' "$T/n1" "$T/n1/n2/n3" | sort -u)" "$U1"
    run v-add-fs-directory $U1 "$T/n1/n2/"
    assert_success
    run v-add-fs-directory $U1 "$T/new/../escape"
    assert_failure 10
    run v-add-fs-file $U1 "$T/n1/f.txt"
    assert_success
    assert_equal "$(stat -c '%U' "$T/n1/f.txt")" "$U1"
    run v-add-fs-file $U1 "$T/missing/f.txt"
    assert_failure 3

    as_user sh -c "printf data > '$T/n1/f.txt'"
    run v-change-fs-file-permission $U1 "$T/n1/f.txt" 0600
    assert_success
    assert_equal "$(stat -c '%a' "$T/n1/f.txt")" "600"
    run v-change-fs-file-permission $U1 "$T/n1/f.txt" 'u+x'
    assert_failure 2
    run v-check-fs-permission $U1 "$T/n1/f.txt"
    assert_success
    run v-open-fs-file $U1 "$T/n1/f.txt"
    assert_success
    assert_output 'data'
    run v-open-fs-file $U1 "$T/n1/none.txt"
    assert_failure 2

    run v-copy-fs-file $U1 "$T/n1/f.txt" "$T/copy.txt"
    assert_success
    assert_equal "$(stat -c '%U' "$T/copy.txt")" "$U1"
    run v-copy-fs-directory $U1 "$T/n1" "$T/n1copy"
    assert_success
    assert_file_exists "$T/n1copy/f.txt"
    assert_dir_exists "$T/n1copy/n2/n3"
    run v-move-fs-file $U1 "$T/copy.txt" "$T/moved.txt"
    assert_success
    assert_file_not_exists "$T/copy.txt"
    run v-move-fs-directory $U1 "$T/n1copy" "$T/n1moved"
    assert_success
    assert_dir_exists "$T/n1moved/n2"

    run v-add-fs-archive $U1 "$T/pack.tar.gz" "$T/moved.txt" "$T/n1moved"
    assert_success
    assert_equal "$(tar -tzf "$T/pack.tar.gz" | grep -c -F -e 'tmp/t/moved.txt' -e 'tmp/t/n1moved/f.txt')" "2"
    run v-add-fs-archive $U1 "$T/pack.tar.gz" "$T/moved.txt"
    assert_failure 1

    run v-delete-fs-file $U1 "$T/moved.txt"
    assert_success
    assert_file_not_exists "$T/moved.txt"
    run v-delete-fs-directory $U1 "$T/n1moved"
    assert_success
    assert_file_not_exists "$T/n1moved"

    # The home itself is never deleted or moved
    run v-delete-fs-directory $U1 "$H1"
    assert_failure 10
    run v-delete-fs-directory $U1 "$T/.."/..
    assert_failure 10
    run v-move-fs-directory $U1 "$H1" "$T/home"
    assert_failure 10
    assert_dir_exists "$T/n1"
}

@test "File commands refuse a read-only folder with E_FORBIDEN and name it" {
    # Like a domain folder of the hosting layout: the user's, mode 551, with a
    # writable public_html inside
    as_user sh -c "mkdir -p '$T/ro/public_html/d' && printf data > '$T/ro/public_html/f.txt' && printf x > '$T/ro/keep.txt'"
    as_user sh -c "cd '$T/ro/public_html' && tar -czf a.tar.gz f.txt"
    chmod 551 "$T/ro"

    # Into the read-only folder: by name, or into it as a folder
    run v-move-fs-file $U1 "$T/ro/public_html/f.txt" "$T/ro/f.txt"
    assert_failure 10
    assert_output "Error: $T/ro is read-only"
    assert_file_exists "$T/ro/public_html/f.txt"
    run v-move-fs-file $U1 "$T/ro/public_html/f.txt" "$T/ro"
    assert_failure 10
    run v-move-fs-directory $U1 "$T/ro/public_html/d" "$T/ro/d"
    assert_failure 10
    run v-copy-fs-file $U1 "$T/ro/public_html/f.txt" "$T/ro/copy.txt"
    assert_failure 10
    run v-copy-fs-directory $U1 "$T/ro/public_html/d" "$T/ro/dcopy"
    assert_failure 10
    run v-add-fs-file $U1 "$T/ro/new.txt"
    assert_failure 10
    run v-add-fs-directory $U1 "$T/ro/new/sub"
    assert_failure 10
    assert_output "Error: $T/ro is read-only"
    run v-add-fs-archive $U1 "$T/ro/pack.tar.gz" "$T/ro/public_html/f.txt"
    assert_failure 10
    run v-extract-fs-archive $U1 "$T/ro/public_html/a.tar.gz" "$T/ro/out"
    assert_failure 10
    assert_file_not_exists "$T/ro/out"

    # Out of the read-only folder
    run v-move-fs-file $U1 "$T/ro/keep.txt" "$T/keep.txt"
    assert_failure 10
    run v-delete-fs-file $U1 "$T/ro/keep.txt"
    assert_failure 10
    assert_file_exists "$T/ro/keep.txt"
    run v-delete-fs-directory $U1 "$T/ro/public_html"
    assert_failure 10
    assert_dir_exists "$T/ro/public_html"

    # The home directory belongs to root
    run v-add-fs-file $U1 "$H1/new.txt"
    assert_failure 10
    assert_output "Error: $H1 is read-only"

    # A folder that exists already, and writable folders below, still work
    run v-add-fs-directory $U1 "$T/ro/public_html"
    assert_success
    run v-move-fs-file $U1 "$T/ro/public_html/f.txt" "$T/ro/public_html/d"
    assert_success
    assert_file_exists "$T/ro/public_html/d/f.txt"
    run v-extract-fs-archive $U1 "$T/ro/public_html/a.tar.gz" "$T/ro/public_html/out"
    assert_success
    chmod 751 "$T/ro"
}

@test "Extract unpacks zip, tar.gz, tgz, tar.bz2, tbz and tar.zst into a new folder" {
    as_user sh -c "mkdir -p '$T/src/sub'; printf hello > '$T/src/sub/a.txt'"
    as_user sh -c "cd '$T/src' && zip -qr ../a.zip . && tar -czf ../a.tar.gz . && tar -czf ../a.tgz . \
        && tar -cjf ../a.tar.bz2 . && tar -cjf ../a.tbz . && tar -I zstd -cf ../a.tar.zst ."
    for archive in a.zip a.tar.gz a.tgz a.tar.bz2 a.tbz a.tar.zst; do
        dst="$T/out/${archive//./-}/new"
        run v-extract-fs-archive $U1 "$T/$archive" "$dst"
        assert_success
        assert_equal "$(cat "$dst/sub/a.txt")" "hello"
        assert_equal "$(stat -c '%U' "$dst" "$dst/sub/a.txt" | sort -u)" "$U1"
        assert_file_exists "$T/$archive"
    done
    as_user sh -c "printf text > '$T/plain.txt'"
    run v-extract-fs-archive $U1 "$T/plain.txt" "$T/out/plain"
    assert_failure 2
}

@test "Extract unpacks 7z into a new folder and keeps the archive" {
    sevenzip=$(command -v 7z 7za 7zz | head -n 1)
    as_user sh -c "mkdir -p '$T/src/sub'; printf hello > '$T/src/sub/a.txt'"
    if [ -z "$sevenzip" ]; then
        printf 'not a 7z archive' > "$T/a.7z"
        run v-extract-fs-archive $U1 "$T/a.7z" "$T/x7/new"
        assert_failure 3
        skip '7-Zip (7z, 7za or 7zz) is not installed on this server'
    fi
    as_user sh -c "cd '$T/src' && '$sevenzip' a -bd -y ../a.7z sub > /dev/null"
    run v-extract-fs-archive $U1 "$T/a.7z" "$T/x7/new"
    assert_success
    assert_equal "$(cat "$T/x7/new/sub/a.txt")" "hello"
    assert_equal "$(stat -c '%U' "$T/x7/new/sub/a.txt")" "$U1"
    assert_file_exists "$T/a.7z"
}

@test "Extract of a single .gz, .bz2 or .zst file moves it into the folder and decompresses it" {
    as_user sh -c "cd '$T' && printf one > g.txt && gzip g.txt && printf two > b.txt && bzip2 b.txt \
        && printf three > z.txt && zstd -q --rm z.txt"
    run v-extract-fs-archive $U1 "$T/g.txt.gz" "$T/single-g"
    assert_success
    assert_equal "$(cat "$T/single-g/g.txt")" "one"
    assert_file_not_exists "$T/g.txt.gz"
    run v-extract-fs-archive $U1 "$T/b.txt.bz2" "$T/single-b"
    assert_success
    assert_equal "$(cat "$T/single-b/b.txt")" "two"
    assert_file_not_exists "$T/b.txt.bz2"
    run v-extract-fs-archive $U1 "$T/z.txt.zst" "$T/single-z"
    assert_success
    assert_equal "$(cat "$T/single-z/z.txt")" "three"
    assert_file_not_exists "$T/z.txt.zst"
    assert_equal "$(stat -c '%U' "$T/single-g/g.txt" "$T/single-b/b.txt" "$T/single-z/z.txt" | sort -u)" "$U1"
}
