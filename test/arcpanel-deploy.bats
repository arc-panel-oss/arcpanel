#!/usr/bin/env bats
# Git Deploy for every web domain (E20,
# docs/features/specs/2026-10-29-001-e20-git-deploy-design.md).
# Run as root on a test server with a PHP-FPM backend and systemd.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
TPL_DIR=$ARCPANEL/data/templates/web/php-fpm
BACKUP=/backup
PASS="Tt-e20-Pass"
U1=e20du1
U2=e20du2
P1=e20-plan
P2=e20-nodeploy
D1=e20d1.example
D2=e20d2.example
D3=e20d3.example
WORK=/root/arcbats-deploy
SAVE=/root/arcbats-deploy-save

conf_value() {
    grep -oE "(^| )$3='[^']*'" "$ARCPANEL/data/users/$1/deploy/$2.conf" | head -n1 | cut -d"'" -f2
}

web_value() {
    grep -F "DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -oE "(^| )$3='[^']*'" | cut -d"'" -f2
}

task_value() {
    grep -oE "(^| )$3='[^']*'" "$ARCPANEL/data/users/$1/tasks/$2.conf" | cut -d"'" -f2
}

# Wait until the task of USER ID has ended (succeeded or failed)
wait_task() {
    local i status
    for i in $(seq 1 240); do
        status=$(task_value "$1" "$2" STATUS)
        if [ "$status" = 'succeeded' ] || [ "$status" = 'failed' ]; then
            echo "$status"
            return 0
        fi
        sleep 0.5
    done
    echo "timeout:$status"
    return 1
}

# Body of http://DOMAIN/PATH through the domain's own address
fetch() {
    local ip
    ip=$(web_value "$U1" "$1" IP)
    for file in "$ARCPANEL"/data/ips/*; do
        if grep -q "NAT='$ip'" "$file"; then
            ip=$(basename "$file")
        fi
    done
    curl -s --max-time 10 --resolve "$1:80:$ip" "http://$1$2"
}

# An archive of a site whose index.html says TEXT, in DIR/NAME.tar.gz
make_site() {
    local dir="$WORK/site-$2"
    rm -rf "$dir"
    mkdir -p "$dir/public" "$dir/storage"
    echo "$1" > "$dir/index.html"
    echo "$1" > "$dir/public/index.html"
    echo "release $1" > "$dir/storage/note.txt"
    tar -czf "$WORK/$2.tar.gz" -C "$dir" .
}

deploy_archive() {
    local task
    task=$(v-schedule-web-domain-deploy "$1" "$2" --artifact "$3")
    wait_task "$1" "$task" > /dev/null
    echo "$task"
}

make_package() {
    local tmp
    tmp=$(mktemp)
    cp "$PKG_DIR/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$1" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$1" > /dev/null
    rm -f "$tmp"
}

cleanup() {
    for user in "$U1" "$U2"; do
        v-delete-user "$user" yes > /dev/null 2>&1 || true
    done
    for package in "$P1" "$P2"; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
    rm -f "$TPL_DIR/arcbats-nobasedir.tpl" "$TPL_DIR/arcbats-nobasedir.sh"
    rm -rf "$WORK"
}

setup_file() {
    cleanup
    mkdir -p "$WORK" "$SAVE"
    cp -p "$ARCPANEL/conf/arcpanel.conf" "$SAVE/"
    make_package "$P1"
    make_package "$P2"
    echo "GIT_DEPLOY='no'" >> "$PKG_DIR/$P2.tenancy"
    v-add-user "$U1" "$PASS" "$U1@example.invalid" "$P1" > /dev/null
    v-add-user "$U2" "$PASS" "$U2@example.invalid" "$P2" > /dev/null
    v-add-web-domain "$U1" "$D1" > /dev/null
    v-add-web-domain "$U1" "$D2" > /dev/null
    v-add-web-domain "$U1" "$D3" > /dev/null
    v-add-web-domain "$U2" "$D1.u2" > /dev/null
    # A pool template without the paths of D17
    sed -E 's#:/home/%user%/web/%domain%/(releases|shared)##g' "$TPL_DIR/default.tpl" > "$TPL_DIR/arcbats-nobasedir.tpl"
}

teardown_file() {
    cleanup
    cp -p "$SAVE/arcpanel.conf" "$ARCPANEL/conf/arcpanel.conf"
    rm -rf "$SAVE"
}

#----------------------------------------------------------#
#                 M1: configuration                        #
#----------------------------------------------------------#

@test "D17: the shipped pool templates allow the releases and shared folders" {
    for tpl in "$ARCPANEL/install/deb/php-fpm/multiphp.tpl" "$ARCPANEL"/install/deb/templates/web/php-fpm/{default,socket}.tpl; do
        run grep -E 'open_basedir\] = .*/home/%user%/web/%domain%/releases:/home/%user%/web/%domain%/shared' "$tpl"
        assert_success
    done
}

@test "add: push mode writes the configuration of a new deploy" {
    run v-add-web-domain-deploy "$U1" "$D1" push
    assert_success
    [ "$(conf_value "$U1" "$D1" MODE)" = 'push' ]
    [ "$(conf_value "$U1" "$D1" STATE)" = 'new' ]
    [ "$(conf_value "$U1" "$D1" KEEP)" = '5' ]
    [ "$(conf_value "$U1" "$D1" PRESET)" = 'none' ]
    [ "$(conf_value "$U1" "$D1" WEBROOT)" = '.' ]
    [[ "$(conf_value "$U1" "$D1" ID)" =~ ^[a-z0-9]{12}$ ]]
    assert_file_permission 640 "$ARCPANEL/data/users/$U1/deploy/$D1.conf"
    # The domain's pool can read the releases (D17)
    run grep -rl "/home/$U1/web/$D1/releases" /etc/php/*/fpm/pool.d/
    assert_success
}

@test "add: a second add is refused" {
    run v-add-web-domain-deploy "$U1" "$D1" push
    assert_failure 4
}

@test "add: refused for a package with GIT_DEPLOY=no" {
    run v-add-web-domain-deploy "$U2" "$D1.u2" push
    assert_failure 10
    assert_file_not_exists "$ARCPANEL/data/users/$U2/deploy/$D1.u2.conf"
}

@test "add: refused when the pool template lacks the paths of D17" {
    v-change-web-domain-backend-tpl "$U1" "$D3" arcbats-nobasedir > /dev/null
    run v-add-web-domain-deploy "$U1" "$D3" push
    assert_failure 11
    v-change-web-domain-backend-tpl "$U1" "$D3" default > /dev/null
}

@test "add: refuses an unknown mode and preset" {
    run v-add-web-domain-deploy "$U1" "$D2" ftp
    assert_failure 2
    run v-add-web-domain-deploy "$U1" "$D2" push '' '' symfony
    assert_failure 2
}

@test "change: validates KEEP, HEALTH_PATH, SHARED and WEBROOT" {
    run v-change-web-domain-deploy "$U1" "$D1" KEEP 0
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" KEEP 21
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" HEALTH_PATH up
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" SHARED '/etc/passwd'
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" SHARED 'a/../../b'
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" SHARED "$(seq -s ' ' 1 21)"
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" WEBROOT '../x'
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" COLOR blue
    assert_failure 2
    run v-change-web-domain-deploy "$U1" "$D1" KEEP 2
    assert_success
    run v-change-web-domain-deploy "$U1" "$D1" SHARED 'storage/ .env'
    assert_success
    [ "$(conf_value "$U1" "$D1" SHARED)" = 'storage/ .env' ]
}

@test "list: json carries the configuration and no secret" {
    run v-list-web-domain-deploy "$U1" "$D1" json
    assert_success
    echo "$output" | jq -e ".\"$D1\".MODE == \"push\" and .\"$D1\".STATE == \"new\" and .\"$D1\".SUPPORTED == \"yes\"" > /dev/null
    echo "$output" | jq -e ".\"$D1\".UPLOAD_URL | test(\"/upload/[a-z0-9]{12}$\")" > /dev/null
    run v-list-web-domain-deploy "$U1" "$D2" json
    assert_success
    echo "$output" | jq -e ".\"$D2\".STATE == \"off\"" > /dev/null
}

#----------------------------------------------------------#
#                 M1: deploy, switch, rollback              #
#----------------------------------------------------------#

@test "deploy: the first archive becomes current and moves the docroot" {
    make_site one one
    prev=$(web_value "$U1" "$D1" CUSTOM_DOCROOT)
    task=$(deploy_archive "$U1" "$D1" "$WORK/one.tar.gz")
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ] || cat "$ARCPANEL/data/users/$U1/tasks/$task.log"
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
    [ "$(task_value "$U1" "$task" KIND)" = 'deploy' ]
    release=$(conf_value "$U1" "$D1" CURRENT)
    [[ "$release" =~ ^[0-9]{8}-[0-9]{6}$ ]]
    [ "$(readlink "/home/$U1/web/$D1/current")" = "releases/$release" ]
    [ "$(conf_value "$U1" "$D1" STATE)" = 'active' ]
    [ "$(conf_value "$U1" "$D1" PREV_DOCROOT)" = "$prev" ]
    [ "$(web_value "$U1" "$D1" CUSTOM_DOCROOT)" = "/home/$U1/web/$D1/current" ]
    [ "$(stat -c %U "/home/$U1/web/$D1/releases/$release/index.html")" = "$U1" ]
    assert_file_exists "/home/$U1/web/$D1/releases/$release/.arcpanel-release"
    grep -q "TASK='$task'" "/home/$U1/web/$D1/releases/$release/.arcpanel-release"
    # public_html is never touched (D8)
    assert_file_exists "/home/$U1/web/$D1/public_html/index.html"
    for i in $(seq 1 20); do
        [ "$(fetch "$D1" /index.html)" = 'one' ] && break
        sleep 0.5
    done
    [ "$(fetch "$D1" /index.html)" = 'one' ]
}

@test "deploy: shared paths are created from the release and linked" {
    [ -L "/home/$U1/web/$D1/current/storage" ]
    [ "$(readlink -f "/home/$U1/web/$D1/current/storage")" = "/home/$U1/web/$D1/shared/storage" ]
    assert_file_exists "/home/$U1/web/$D1/shared/storage/note.txt"
    grep -q 'release one' "/home/$U1/web/$D1/shared/storage/note.txt"
    # .env did not exist: an empty file is shared
    [ -L "/home/$U1/web/$D1/current/.env" ]
    assert_file_exists "/home/$U1/web/$D1/shared/.env"
}

@test "deploy: a new release switches current, shared data stays" {
    make_site two two
    task=$(deploy_archive "$U1" "$D1" "$WORK/two.tar.gz")
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
    for i in $(seq 1 20); do
        [ "$(fetch "$D1" /index.html)" = 'two' ] && break
        sleep 0.5
    done
    [ "$(fetch "$D1" /index.html)" = 'two' ]
    grep -q 'release one' "/home/$U1/web/$D1/shared/storage/note.txt"
    [ "$(ls "/home/$U1/web/$D1/releases" | wc -l)" -eq 2 ]
}

@test "deploy: WEBROOT public serves current/public" {
    v-change-web-domain-deploy "$U1" "$D1" WEBROOT public
    [ "$(web_value "$U1" "$D1" CUSTOM_DOCROOT)" = "/home/$U1/web/$D1/current/public" ]
    v-change-web-domain-deploy "$U1" "$D1" WEBROOT .
    [ "$(web_value "$U1" "$D1" CUSTOM_DOCROOT)" = "/home/$U1/web/$D1/current" ]
}

@test "prune: keeps KEEP releases and never removes current" {
    make_site three three
    task=$(deploy_archive "$U1" "$D1" "$WORK/three.tar.gz")
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
    [ "$(ls "/home/$U1/web/$D1/releases" | wc -l)" -eq 2 ]
    [ -d "/home/$U1/web/$D1/releases/$(conf_value "$U1" "$D1" CURRENT)" ]
}

@test "backup: carries current and shared/ only; restore rebuilds the layout (D14)" {
    web="/home/$U1/web/$D1"
    current=$(conf_value "$U1" "$D1" CURRENT)
    old=$(ls "$web/releases" | grep -vx "$current" | head -n1)
    [ -n "$old" ]
    rm -f "$BACKUP/$U1".*.tar
    run v-backup-user "$U1"
    assert_success
    backup=$(basename "$(ls -t "$BACKUP/$U1".*.tar | head -n1)")
    rm -rf "$WORK/backup"
    mkdir -p "$WORK/backup"
    tar -xf "$BACKUP/$backup" -C "$WORK/backup" "./web/$D1"
    list=$(tar -tf "$WORK/backup/web/$D1"/domain_data.tar.*)
    echo "$list" | grep -qx "./releases/$current/index.html"
    echo "$list" | grep -qx './current'
    echo "$list" | grep -qx './shared/storage/note.txt'
    [ -z "$(echo "$list" | grep "^./releases/$old")" ]

    # Restore into a domain whose deploy folders are gone (the older release
    # is put back afterwards for the tests that follow)
    rm -rf "$WORK/old-release"
    mv "$web/releases/$old" "$WORK/old-release"
    rm -rf "$web/releases" "$web/shared" "$web/current"
    run v-restore-user "$U1" "$backup" "$D1" no no no no no
    assert_success
    [ "$(readlink "$web/current")" = "releases/$current" ]
    [ "$(ls "$web/releases")" = "$current" ]
    assert_file_exists "$web/shared/storage/note.txt"
    [ "$(stat -c %G "$web/releases")" = 'www-data' ]
    [ "$(stat -c %G "$web/shared")" = 'www-data' ]
    [ "$(stat -c %a "$web/releases")" = '750' ]
    [ "$(stat -c %U "$web/releases/$current/index.html")" = "$U1" ]
    for i in $(seq 1 20); do
        [ "$(fetch "$D1" /index.html)" = 'three' ] && break
        sleep 0.5
    done
    [ "$(fetch "$D1" /index.html)" = 'three' ]
    mv "$WORK/old-release" "$web/releases/$old"
    v-delete-user-backup "$U1" "$backup" > /dev/null 2>&1 || rm -f "$BACKUP/$backup"
}

@test "backup: backup_deploy_stale lists the releases current does not point to" {
    dir="$WORK/stale"
    rm -rf "$dir"
    mkdir -p "$dir/releases/20260101-000000" "$dir/releases/20260102-000000" "$dir/releases/20260103-000000"
    ln -s releases/20260102-000000 "$dir/current"
    run bash -c "source $ARCPANEL/func/main.sh; source $ARCPANEL/func/backup.sh; backup_deploy_stale '$dir'"
    assert_success
    assert_output $'20260101-000000\n20260103-000000'
    # Without deploy, or with releases/ as a link, nothing is excluded
    rm "$dir/current"
    run bash -c "source $ARCPANEL/func/main.sh; source $ARCPANEL/func/backup.sh; backup_deploy_stale '$dir'"
    assert_output ''
    ln -s releases/20260102-000000 "$dir/current"
    mv "$dir/releases" "$dir/r"
    ln -s r "$dir/releases"
    run bash -c "source $ARCPANEL/func/main.sh; source $ARCPANEL/func/backup.sh; backup_deploy_stale '$dir'"
    assert_output ''
}

@test "deploys: lists releases and the deploy tasks" {
    run v-list-web-domain-deploys "$U1" "$D1" json
    assert_success
    echo "$output" | jq -e '.releases | length == 2' > /dev/null
    echo "$output" | jq -e '[.releases[] | select(.CURRENT == "yes")] | length == 1' > /dev/null
    echo "$output" | jq -e '.tasks | length >= 3' > /dev/null
    echo "$output" | jq -e '.tasks[0].STATUS == "succeeded"' > /dev/null
}

@test "rollback: switches current to an older release" {
    current=$(conf_value "$U1" "$D1" CURRENT)
    older=$(ls "/home/$U1/web/$D1/releases" | grep -vx "$current" | head -n1)
    task=$(v-rollback-web-domain-deploy "$U1" "$D1" "$older")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ]
    [ "$(conf_value "$U1" "$D1" CURRENT)" = "$older" ]
    [ "$(readlink "/home/$U1/web/$D1/current")" = "releases/$older" ]
    for i in $(seq 1 20); do
        [ "$(fetch "$D1" /index.html)" = 'two' ] && break
        sleep 0.5
    done
    [ "$(fetch "$D1" /index.html)" = 'two' ]
}

@test "rollback: refuses a release that does not exist" {
    run v-rollback-web-domain-deploy "$U1" "$D1" 19990101-000000
    assert_failure 3
    run v-rollback-web-domain-deploy "$U1" "$D1" '../../etc'
    assert_failure 2
}

@test "health check: a failing check rolls back to the previous release" {
    v-change-web-domain-deploy "$U1" "$D1" HEALTH_PATH /health.txt
    before=$(conf_value "$U1" "$D1" CURRENT)
    make_site four four
    task=$(deploy_archive "$U1" "$D1" "$WORK/four.tar.gz")
    [ "$(task_value "$U1" "$task" STATUS)" = 'failed' ]
    [[ "$(task_value "$U1" "$task" ERROR)" == "health check failed, rolled back to $before" ]]
    [ "$(conf_value "$U1" "$D1" CURRENT)" = "$before" ]
    [ "$(readlink "/home/$U1/web/$D1/current")" = "releases/$before" ]
    # A healthy release passes
    mkdir -p "$WORK/site-five"
    make_site five five
    echo ok > "$WORK/site-five/health.txt"
    tar -czf "$WORK/five.tar.gz" -C "$WORK/site-five" .
    task=$(deploy_archive "$U1" "$D1" "$WORK/five.tar.gz")
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
    v-change-web-domain-deploy "$U1" "$D1" HEALTH_PATH ''
}

#----------------------------------------------------------#
#                 M1: archive checks                       #
#----------------------------------------------------------#

refused() {
    local before task
    before=$(conf_value "$U1" "$D1" CURRENT)
    task=$(deploy_archive "$U1" "$D1" "$1")
    [ "$(task_value "$U1" "$task" STATUS)" = 'failed' ]
    [ "$(conf_value "$U1" "$D1" CURRENT)" = "$before" ]
    # Nothing unpacked stays
    [ "$(ls "/home/$U1/web/$D1/releases" | wc -l)" -le 2 ]
    task_value "$U1" "$task" ERROR
}

@test "archive: refuses an absolute name" {
    mkdir -p "$WORK/abs"
    echo x > "$WORK/abs/file"
    tar -P -czf "$WORK/abs.tar.gz" "$WORK/abs/file"
    run refused "$WORK/abs.tar.gz"
    assert_success
    assert_output --partial 'refused archive entry'
}

@test "archive: refuses a .. segment" {
    mkdir -p "$WORK/dots/in"
    echo x > "$WORK/dots/evil"
    tar -P -czf "$WORK/dots.tar.gz" -C "$WORK/dots/in" ../evil
    run refused "$WORK/dots.tar.gz"
    assert_success
    assert_output --partial 'refused archive entry'
}

@test "archive: refuses a symlink that leaves the release" {
    mkdir -p "$WORK/link/a"
    ln -s ../../etc "$WORK/link/a/out"
    tar -czf "$WORK/link.tar.gz" -C "$WORK/link" .
    run refused "$WORK/link.tar.gz"
    assert_success
    assert_output --partial 'a/out'
    # A link inside the release is fine
    rm -rf "$WORK/link"
    mkdir -p "$WORK/link/a"
    echo in > "$WORK/link/index.html"
    ln -s ../index.html "$WORK/link/a/in"
    tar -czf "$WORK/link.tar.gz" -C "$WORK/link" .
    task=$(deploy_archive "$U1" "$D1" "$WORK/link.tar.gz")
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
}

@test "archive: refuses a hard link and a device" {
    mkdir -p "$WORK/hard"
    echo x > "$WORK/hard/a"
    ln "$WORK/hard/a" "$WORK/hard/b"
    tar -czf "$WORK/hard.tar.gz" -C "$WORK/hard" .
    run refused "$WORK/hard.tar.gz"
    assert_success
    mkdir -p "$WORK/dev"
    mknod "$WORK/dev/null2" c 1 3
    tar -czf "$WORK/dev.tar.gz" -C "$WORK/dev" .
    run refused "$WORK/dev.tar.gz"
    assert_success
}

@test "archive: refuses an archive above DEPLOY_MAX_ARTIFACT" {
    v-change-sys-config-value DEPLOY_MAX_ARTIFACT 1M
    head -c 2097152 /dev/urandom > "$WORK/big.bin"
    tar -czf "$WORK/big.tar.gz" -C "$WORK" big.bin
    run v-schedule-web-domain-deploy "$U1" "$D1" --artifact "$WORK/big.tar.gz"
    assert_failure 8
    # The unpacked size is limited to four times the limit
    head -c 5242880 /dev/zero > "$WORK/zero.bin"
    tar -czf "$WORK/zero.tar.gz" -C "$WORK" zero.bin
    run refused "$WORK/zero.tar.gz"
    assert_success
    assert_output --partial 'unpacked size'
    v-change-sys-config-value DEPLOY_MAX_ARTIFACT 512M
}

#----------------------------------------------------------#
#                 M1: concurrency                          #
#----------------------------------------------------------#

@test "lock: requests that arrive during a deploy collapse into the newest" {
    # A slow first deploy: the health check waits for a file that never comes
    v-change-web-domain-deploy "$U1" "$D1" HEALTH_PATH /never.txt
    make_site six six
    make_site seven seven
    make_site eight eight
    t1=$(v-schedule-web-domain-deploy "$U1" "$D1" --artifact "$WORK/six.tar.gz")
    sleep 2
    t2=$(v-schedule-web-domain-deploy "$U1" "$D1" --artifact "$WORK/seven.tar.gz")
    t3=$(v-schedule-web-domain-deploy "$U1" "$D1" --artifact "$WORK/eight.tar.gz")
    [ "$(task_value "$U1" "$t2" STATUS)" = 'failed' ]
    [ "$(task_value "$U1" "$t2" ERROR)" = 'replaced by a newer request' ]
    [ "$(task_value "$U1" "$t3" STATUS)" = 'queued' ]
    [ "$(conf_value "$U1" "$D1" PENDING)" = "$t3" ]
    v-change-web-domain-deploy "$U1" "$D1" HEALTH_PATH ''
    wait_task "$U1" "$t1"
    [ "$(wait_task "$U1" "$t3")" = 'succeeded' ]
    [ "$(conf_value "$U1" "$D1" PENDING)" = '' ]
    for i in $(seq 1 20); do
        [ "$(fetch "$D1" /index.html)" = 'eight' ] && break
        sleep 0.5
    done
    [ "$(fetch "$D1" /index.html)" = 'eight' ]
}

@test "suspend: a suspended domain refuses deploys" {
    v-suspend-web-domain "$U1" "$D1" > /dev/null
    run v-schedule-web-domain-deploy "$U1" "$D1" --artifact "$WORK/one.tar.gz"
    assert_failure 5
    v-unsuspend-web-domain "$U1" "$D1" > /dev/null
}

#----------------------------------------------------------#
#                 M2: pull mode                            #
#----------------------------------------------------------#

GITUSER=e20git
REPO=/home/e20git/site.git
REPO_URL="ssh://e20git@localhost/home/e20git/site.git"

# Commit the files of $WORK/repo (after the caller changed them) and push
repo_push() {
    git -C "$WORK/repo" add -A
    git -C "$WORK/repo" -c user.email=e20@example.invalid -c user.name=e20 commit -qm "$1"
    # The local receive-pack runs as root: root owns the repository meanwhile
    [ -d "$REPO" ] && chown -R root:root "$REPO"
    git -C "$WORK/repo" push -q origin main
    chown -R "$GITUSER:$GITUSER" "$REPO"
    git -C "$WORK/repo" rev-parse HEAD
}

@test "pull: refuses file:// and local repositories, bad branches, a token over ssh" {
    run v-add-web-domain-deploy "$U1" "$D3" pull file:///home/e20git/site.git
    assert_failure 2
    run v-add-web-domain-deploy "$U1" "$D3" pull /home/e20git/site.git
    assert_failure 2
    run v-add-web-domain-deploy "$U1" "$D3" pull "$REPO_URL" 'main..x'
    assert_failure 2
    run v-add-web-domain-deploy "$U1" "$D3" pull "$REPO_URL" main none token
    assert_failure 2
    assert_file_not_exists "$ARCPANEL/data/users/$U1/deploy/$D3.conf"
}

@test "pull: add prints the public key and the webhook secret once" {
    userdel -r "$GITUSER" > /dev/null 2>&1 || true
    useradd -m -s /bin/bash "$GITUSER"
    git init -q --bare -b main "$REPO"
    rm -rf "$WORK/repo"
    git init -q -b main "$WORK/repo"
    git -C "$WORK/repo" remote add origin "$REPO"
    echo 'pulled one' > "$WORK/repo/index.html"
    repo_push one > /dev/null

    run v-add-web-domain-deploy "$U1" "$D3" pull "$REPO_URL" main none
    assert_success
    assert_output --regexp "PUBLIC_KEY='ssh-ed25519 [A-Za-z0-9+/=]+ arcpanel-deploy@$D3'"
    assert_output --regexp "WEBHOOK_SECRET='[A-Za-z0-9]{40}'"
    [ "$(conf_value "$U1" "$D3" AUTH)" = 'key' ]
    # Secrets live in the store, not in the configuration
    run grep -c 'PRIVATE KEY\|WEBHOOK' "$ARCPANEL/data/users/$U1/deploy/$D3.conf"
    assert_output 0
    # The public key is not secret: Settings shows it again
    grep -q "PUBLIC_KEY='ssh-ed25519 " "$ARCPANEL/data/users/$U1/deploy/$D3.conf"
    run v-list-user-secret "$U1" "/deploy/$D3/key" json
    assert_success
    run v-list-user-secret "$U1" "/deploy/$D3/webhook" json
    assert_success
    mkdir -p "/home/$GITUSER/.ssh"
    v-add-web-domain-deploy "$U1" "$D3" pull "$REPO_URL" 2>&1 | grep -q 'already on'
}

@test "pull: a key the Git host does not know fails with Git's last line" {
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'failed' ]
    run task_value "$U1" "$task" ERROR
    assert_output --partial 'Permission denied'
    [ "$(conf_value "$U1" "$D3" CURRENT)" = '' ]
    [ "$(ls "/home/$U1/web/$D3/releases" | wc -l)" -eq 0 ]
    # The copy of the key is gone
    [ "$(find "/home/$U1/tmp" -name '.arcpanel-deploy.*' | wc -l)" -eq 0 ]
}

@test "pull: deploys the branch with the domain's key" {
    run v-change-web-domain-deploy-credential "$U1" "$D3" key
    assert_success
    echo "${output#PUBLIC_KEY=}" | tr -d "'" > "/home/$GITUSER/.ssh/authorized_keys"
    chown -R "$GITUSER:$GITUSER" "/home/$GITUSER/.ssh"
    chmod 700 "/home/$GITUSER/.ssh"
    chmod 600 "/home/$GITUSER/.ssh/authorized_keys"
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ] || cat "$ARCPANEL/data/users/$U1/tasks/$task.log"
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
    release=$(conf_value "$U1" "$D3" CURRENT)
    grep -q "SOURCE='pull'" "/home/$U1/web/$D3/releases/$release/.arcpanel-release"
    grep -q "REF='refs/heads/main'" "/home/$U1/web/$D3/releases/$release/.arcpanel-release"
    grep -qE "COMMIT='[0-9a-f]{40}'" "/home/$U1/web/$D3/releases/$release/.arcpanel-release"
    assert_file_not_exists "/home/$U1/web/$D3/releases/$release/.git"
    [ "$(find "/home/$U1/tmp" -name '.arcpanel-deploy.*' | wc -l)" -eq 0 ]
    for i in $(seq 1 20); do
        [ "$(fetch "$D3" /index.html)" = 'pulled one' ] && break
        sleep 0.5
    done
    [ "$(fetch "$D3" /index.html)" = 'pulled one' ]
}

@test "pull: deploys a given commit" {
    first=$(git -C "$WORK/repo" rev-parse HEAD)
    echo 'pulled two' > "$WORK/repo/index.html"
    repo_push two > /dev/null
    task=$(v-schedule-web-domain-deploy "$U1" "$D3" "$first")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ]
    release=$(conf_value "$U1" "$D3" CURRENT)
    grep -q "COMMIT='$first'" "/home/$U1/web/$D3/releases/$release/.arcpanel-release"
    grep -q 'pulled one' "/home/$U1/web/$D3/current/index.html"
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ]
    grep -q 'pulled two' "/home/$U1/web/$D3/current/index.html"
}

@test "preset custom: runs .arcpanel/deploy.sh as the user with its environment" {
    mkdir -p "$WORK/repo/.arcpanel"
    cat > "$WORK/repo/.arcpanel/deploy.sh" << 'EOS'
set -e
echo "custom $DOMAIN $(id -un)" > built.txt
[ -d "$SHARED_DIR" ] && [ "$RELEASE_DIR" = "$PWD" ] && "$PHP" -r 'echo "php ok\n";' >> built.txt
EOS
    repo_push custom > /dev/null
    v-change-web-domain-deploy "$U1" "$D3" PRESET custom
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ] || cat "$ARCPANEL/data/users/$U1/tasks/$task.log"
    grep -q "custom $D3 $U1" "/home/$U1/web/$D3/current/built.txt"
    grep -q 'php ok' "/home/$U1/web/$D3/current/built.txt"
}

@test "preset: a failing step keeps current and removes the release" {
    before=$(conf_value "$U1" "$D3" CURRENT)
    echo 'exit 3' >> "$WORK/repo/.arcpanel/deploy.sh"
    repo_push failing > /dev/null
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'failed' ]
    [ "$(task_value "$U1" "$task" ERROR)" = 'the custom preset failed with exit code 3' ]
    [ "$(conf_value "$U1" "$D3" CURRENT)" = "$before" ]
    [ "$(readlink "/home/$U1/web/$D3/current")" = "releases/$before" ]
    [ "$(ls "/home/$U1/web/$D3/releases" | grep -vx "$before" | wc -l)" -le 4 ]
    sed -i '$d' "$WORK/repo/.arcpanel/deploy.sh"
    repo_push fixed > /dev/null
}

@test "preset static: serves dist; a build script needs Node.js" {
    v-change-web-domain-deploy "$U1" "$D3" PRESET static
    v-change-web-domain-deploy "$U1" "$D3" WEBROOT dist
    mkdir -p "$WORK/repo/dist"
    echo 'static dist' > "$WORK/repo/dist/index.html"
    repo_push static > /dev/null
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ]
    [ "$(web_value "$U1" "$D3" CUSTOM_DOCROOT)" = "/home/$U1/web/$D3/current/dist" ]
    echo '{"scripts":{"build":"echo build"}}' > "$WORK/repo/package.json"
    repo_push npm > /dev/null
    if [ -z "$(ls -d /home/$U1/.nvm/versions/node/v*/bin 2> /dev/null)" ]; then
        task=$(v-schedule-web-domain-deploy "$U1" "$D3")
        [ "$(wait_task "$U1" "$task")" = 'failed' ]
        grep -q 'Node.js is not installed' "$ARCPANEL/data/users/$U1/tasks/$task.log"
    fi
    rm -f "$WORK/repo/package.json"
    repo_push nonpm > /dev/null
}

@test "preset laravel: composer install, migrate before the switch, optimize" {
    rm -rf "$WORK/repo/dist" "$WORK/repo/.arcpanel"
    mkdir -p "$WORK/repo/public" "$WORK/repo/storage"
    echo 'laravel' > "$WORK/repo/public/index.html"
    echo '{"name":"arcpanel/e20-fixture","require":{}}' > "$WORK/repo/composer.json"
    cat > "$WORK/repo/artisan" << 'EOS'
<?php
$cmd = $argv[1] ?? '';
if ($cmd === 'migrate' && in_array('--force', $argv, true)) {
    file_put_contents(__DIR__.'/storage/migrated', getmypid());
} elseif ($cmd === 'optimize') {
    file_put_contents(__DIR__.'/optimized', '1');
}
EOS
    repo_push laravel > /dev/null
    v-change-web-domain-deploy "$U1" "$D3" PRESET laravel
    v-change-web-domain-deploy "$U1" "$D3" WEBROOT public
    v-change-web-domain-deploy "$U1" "$D3" SHARED 'storage/ .env'
    v-change-web-domain-deploy "$U1" "$D3" MIGRATE yes
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'succeeded' ] || tail -n 30 "$ARCPANEL/data/users/$U1/tasks/$task.log"
    [ "$(task_value "$U1" "$task" STATUS)" = 'succeeded' ]
    assert_file_exists "/home/$U1/web/$D3/current/vendor/autoload.php"
    assert_file_exists "/home/$U1/web/$D3/current/optimized"
    # The migration ran in the release, through the shared storage
    assert_file_exists "/home/$U1/web/$D3/shared/storage/migrated"
    [ "$(web_value "$U1" "$D3" CUSTOM_DOCROOT)" = "/home/$U1/web/$D3/current/public" ]
}

@test "token: stored from a file; the copy for the fetch is removed" {
    v-change-web-domain-deploy "$U1" "$D3" URL https://127.0.0.1:9/acme/shop.git
    printf 'tok-e20-not-real' > "$WORK/token"
    run v-change-web-domain-deploy-credential "$U1" "$D3" token "$WORK/token"
    assert_success
    [ "$(conf_value "$U1" "$D3" AUTH)" = 'token' ]
    run grep -c 'tok-e20' "$ARCPANEL/data/users/$U1/deploy/$D3.conf"
    assert_output 0
    task=$(v-schedule-web-domain-deploy "$U1" "$D3")
    [ "$(wait_task "$U1" "$task")" = 'failed' ]
    [ -n "$(task_value "$U1" "$task" ERROR)" ]
    run grep -c 'tok-e20' "$ARCPANEL/data/users/$U1/tasks/$task.log"
    assert_output 0
    [ "$(find "/home/$U1/tmp" -name '.arcpanel-deploy.*' | wc -l)" -eq 0 ]
    # A token is one line, for https only
    printf 'a\nb\n' > "$WORK/token2"
    run v-change-web-domain-deploy-credential "$U1" "$D3" token "$WORK/token2"
    assert_failure 2
}

@test "credential id: a new ID changes the URLs" {
    old=$(conf_value "$U1" "$D3" ID)
    run v-change-web-domain-deploy-credential "$U1" "$D3" id
    assert_success
    [ "$(conf_value "$U1" "$D3" ID)" != "$old" ]
    v-list-web-domain-deploy "$U1" "$D3" json | jq -e ".\"$D3\".WEBHOOK_URL | endswith(\"/hooks/$(conf_value "$U1" "$D3" ID)\")" > /dev/null
}

@test "delete: deletes the deploy key, token and webhook secret" {
    v-delete-web-domain-deploy "$U1" "$D3"
    run v-list-user-secret "$U1" "/deploy/$D3/key" json
    assert_failure
    run v-list-user-secret "$U1" "/deploy/$D3/token" json
    assert_failure
    run v-list-user-secret "$U1" "/deploy/$D3/webhook" json
    assert_failure
    userdel -r "$GITUSER" > /dev/null 2>&1 || true
}

#----------------------------------------------------------#
#                 M3: deploy port, server settings         #
#----------------------------------------------------------#

PORT_CONF=/etc/nginx/conf.d/arcpanel-deploy-port.conf

@test "port: open while a domain uses deploy, with its reservation" {
    assert_file_exists "$PORT_CONF"
    run nginx -t
    assert_success
    grep -q "listen      8088 ssl;" "$PORT_CONF"
    grep -q "COMMENT='arcpanel-deploy-port'" "$ARCPANEL/data/ports/.reserved.conf"
    # Only the three paths reach the panel
    run curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:8088/
    assert_output 404
    run curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:8088/api/v2/me
    assert_output 404
    run curl -sk -o /dev/null -w '%{http_code}' -X POST -d '{}' "https://127.0.0.1:8088/hooks/$(conf_value "$U1" "$D1" ID)"
    assert_output 404
}

@test "sys deploy: validates the settings" {
    run v-change-sys-deploy DEPLOY_PORT 80
    assert_failure 2
    run v-change-sys-deploy DEPLOY_PORT 8083
    assert_failure 7
    run v-change-sys-deploy DEPLOY_PORT 99999
    assert_failure 2
    run v-change-sys-deploy DEPLOY_MAX_ARTIFACT 2G
    assert_failure 2
    run v-change-sys-deploy DEPLOY_MAX_ARTIFACT 12
    assert_failure 2
    run v-change-sys-deploy DEPLOY_BUILD_TIMEOUT 30
    assert_failure 2
    run v-change-sys-deploy DEPLOY_OIDC_ISSUERS 'http://token.example.com'
    assert_failure 2
    run v-change-sys-deploy COLOR blue
    assert_failure 2
}

@test "sys deploy: lists the settings, also through v-list-sys-config" {
    run v-change-sys-deploy DEPLOY_BUILD_TIMEOUT 1200
    assert_success
    run v-list-sys-deploy json
    assert_success
    echo "$output" | jq -e '.DEPLOY_PORT == "8088" and .DEPLOY_BUILD_TIMEOUT == "1200" and .PORT_OPEN == "yes"' > /dev/null
    echo "$output" | jq -e '.DEPLOY_OIDC_ISSUERS | contains("https://token.actions.githubusercontent.com")' > /dev/null
    v-list-sys-config json | jq -e '.config.DEPLOY_PORT == "8088" and .config.DEPLOY_BUILD_TIMEOUT == "1200"' > /dev/null
    v-change-sys-deploy DEPLOY_BUILD_TIMEOUT 900
}

@test "sys deploy: a new port moves the NGINX file and the reservation" {
    run v-change-sys-deploy DEPLOY_PORT 8098
    assert_success
    grep -q "listen      8098 ssl;" "$PORT_CONF"
    grep -q "^PORT='8098' .*COMMENT='arcpanel-deploy-port'" "$ARCPANEL/data/ports/.reserved.conf"
    run grep -c "^PORT='8088' .*COMMENT='arcpanel-deploy-port'" "$ARCPANEL/data/ports/.reserved.conf"
    assert_output 0
    v-list-web-domain-deploy "$U1" "$D1" json | jq -e ".\"$D1\".UPLOAD_URL | contains(\":8098/upload/\")" > /dev/null
    run v-change-sys-deploy DEPLOY_PORT 8088
    assert_success
    grep -q "listen      8088 ssl;" "$PORT_CONF"
}

#----------------------------------------------------------#
#                 M4: trusted publishers                   #
#----------------------------------------------------------#

GH=https://token.actions.githubusercontent.com

@test "publisher: added to a push domain, listed, and found by the endpoint" {
    run v-add-web-domain-deploy-publisher "$U1" "$D1" "$GH" acme/shop refs/heads/main '' .github/workflows/deploy.yml
    assert_success
    assert_output p1
    run v-add-web-domain-deploy-publisher "$U1" "$D1" https://gitlab.com/ acme/group/shop '' production
    assert_success
    assert_output p2
    v-list-web-domain-deploy "$U1" "$D1" json | jq -e ".\"$D1\".PUBLISHERS | length == 2 and .[0].SUBJECT_REPO == \"acme/shop\" and .[1].ISSUER == \"https://gitlab.com\"" > /dev/null
    run v-get-web-domain-deploy-endpoint "$(conf_value "$U1" "$D1" ID)" upload
    assert_success
    echo "$output" | jq -e ".USER == \"$U1\" and .DOMAIN == \"$D1\" and (.PUBLISHERS | length == 2) and (.AUDIENCE | endswith(\"/upload/$(conf_value "$U1" "$D1" ID)\")) and .SECRET == \"\"" > /dev/null
}

@test "publisher: refuses an issuer not allowed, a publisher for every branch, bad names" {
    run v-add-web-domain-deploy-publisher "$U1" "$D1" https://evil.example.com acme/shop refs/heads/main
    assert_failure 2
    run v-add-web-domain-deploy-publisher "$U1" "$D1" "$GH" acme/shop '' ''
    assert_failure 2
    run v-add-web-domain-deploy-publisher "$U1" "$D1" "$GH" 'acme shop' refs/heads/main
    assert_failure 2
    run v-add-web-domain-deploy-publisher "$U1" "$D1" "$GH" acme/shop main
    assert_failure 2
    run v-add-web-domain-deploy-publisher "$U1" "$D1" "$GH" acme/shop refs/heads/main '' ../deploy.yml
    assert_failure 2
}

@test "publisher: an issuer the administrator allows can be used" {
    v-change-sys-deploy DEPLOY_OIDC_ISSUERS "$GH,https://gitlab.com,https://git.example.com"
    run v-add-web-domain-deploy-publisher "$U1" "$D1" https://git.example.com acme/shop refs/heads/main
    assert_success
    assert_output p3
    v-change-sys-deploy DEPLOY_OIDC_ISSUERS "$GH,https://gitlab.com"
}

@test "publisher: removed by its id" {
    run v-delete-web-domain-deploy-publisher "$U1" "$D1" p3
    assert_success
    run v-delete-web-domain-deploy-publisher "$U1" "$D1" p3
    assert_failure 3
    v-list-web-domain-deploy "$U1" "$D1" json | jq -e ".\"$D1\".PUBLISHERS | length == 2" > /dev/null
}

@test "endpoint: an unknown id, the other mode or a suspended domain read as unknown" {
    id=$(conf_value "$U1" "$D1" ID)
    run v-get-web-domain-deploy-endpoint aaaaaaaaaaaa upload
    assert_failure 3
    run v-get-web-domain-deploy-endpoint "$id" hook
    assert_failure 3
    v-suspend-web-domain "$U1" "$D1" > /dev/null
    run v-get-web-domain-deploy-endpoint "$id" upload
    assert_failure 3
    v-unsuspend-web-domain "$U1" "$D1" > /dev/null
}

#----------------------------------------------------------#
#                 M1: turning deploy off                   #
#----------------------------------------------------------#

@test "delete: restores the previous docroot and keeps the files" {
    prev=$(conf_value "$U1" "$D1" PREV_DOCROOT)
    run v-delete-web-domain-deploy "$U1" "$D1"
    assert_success
    assert_file_not_exists "$ARCPANEL/data/users/$U1/deploy/$D1.conf"
    [ "$(web_value "$U1" "$D1" CUSTOM_DOCROOT)" = "$prev" ]
    assert_dir_exists "/home/$U1/web/$D1/releases"
    assert_dir_exists "/home/$U1/web/$D1/shared"
    run v-list-web-domain-deploy "$U1" "$D1" json
    echo "$output" | jq -e ".\"$D1\".STATE == \"off\"" > /dev/null
}

@test "delete web domain: removes its deploy configuration" {
    v-add-web-domain-deploy "$U1" "$D2" push > /dev/null
    v-delete-web-domain "$U1" "$D2" > /dev/null
    assert_file_not_exists "$ARCPANEL/data/users/$U1/deploy/$D2.conf"
}

@test "port: closes with the last domain that uses deploy" {
    for file in "$ARCPANEL/data/users/$U1"/deploy/*.conf; do
        [ -e "$file" ] && v-delete-web-domain-deploy "$U1" "$(basename "$file" .conf)"
    done
    # Other accounts of the test server may use deploy
    [ -z "$(ls "$ARCPANEL"/data/users/*/deploy/*.conf 2> /dev/null)" ] || skip "another account uses deploy"
    assert_file_not_exists "$PORT_CONF"
    run grep -c "COMMENT='arcpanel-deploy-port'" "$ARCPANEL/data/ports/.reserved.conf"
    assert_output 0
}
