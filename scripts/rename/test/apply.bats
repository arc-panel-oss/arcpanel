#!/usr/bin/env bats
# Fixture test of scripts/rename/apply.sh (full rename, ADR-0007,
# docs/features/specs/2026-10-06-005-e9-full-rename-design.md §8, §11).
# Runs on the workstation or any Linux host with git and perl; it needs
# no ArcPanel install. It lives under scripts/rename/ so that the rename
# does not rewrite its own fixtures (keep.txt).
#
#   test/test_helper/bats-core/bin/bats scripts/rename/test/apply.bats

load '../../../test/test_helper/bats-support/load'
load '../../../test/test_helper/bats-assert/load'
load '../../../test/test_helper/bats-file/load'

RENAME_DIR="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"

setup() {
    REPO="$BATS_TEST_TMPDIR/repo"
    mkdir -p "$REPO/scripts" "$REPO/bin" "$REPO/func" "$REPO/install/deb/pga/phppgadmin" \
        "$REPO/install/common/snappymail" "$REPO/lib/locale/de/LC_MESSAGES" "$REPO/src"
    cp -r "$RENAME_DIR" "$REPO/scripts/rename"
    cd "$REPO"
    git init -q
    git config user.name fixture
    git config user.email fixture@example.invalid
    git config core.autocrlf false
}

commit_fixtures() {
    git add -A
    git commit -qm fixtures
}

apply() {
    run bash scripts/rename/apply.sh "$@"
}

@test "longer names are replaced before their prefixes" {
    cat > func/order.sh << 'EOF'
source /etc/hestiacp/hestia.conf
cp /etc/hestiacp/local.conf /tmp
REPO=hestiacp
NAME=HestiaCP
systemctl restart hestia-nginx hestia-php hestia-web-terminal
chown hestiaweb:hestia-users /run/hestia-php.sock
su hestiamail
echo "$HESTIA_INSTALL_DIR $HESTIA"
mkdir /root/hst_backups /root/hst_install_backups
hst_cmd_args=1
bash hst-install.sh
bash src/hst_autocompile.sh --hestia
EOF
    commit_fixtures
    apply
    assert_success
    run cat func/order.sh
    assert_line 'source /etc/arcpanel/arcpanel.conf'
    assert_line 'cp /etc/arcpanel/local.conf /tmp'
    assert_line 'REPO=arcpanel'
    assert_line 'NAME=ArcPanel'
    assert_line 'systemctl restart arcpanel-nginx arcpanel-php arcpanel-web-terminal'
    assert_line 'chown arcpanelweb:arcpanel-users /run/arcpanel-php.sock'
    assert_line 'su arcpanelmail'
    assert_line 'echo "$ARCPANEL_INSTALL_DIR $ARCPANEL"'
    assert_line 'mkdir /root/arcpanel_backups /root/arcpanel_install_backups'
    assert_line 'arc_cmd_args=1'
    assert_line 'bash arcpanel-install.sh'
    assert_line 'bash src/arcpanel-build.sh --arcpanel'
    refute_output --regexp 'arcpanelcp|ArcPanelCP|hestia|Hestia|HESTIA'
}

@test "case is kept" {
    cat > bin/v-case << 'EOF'
check_hestia_demo_mode
header('Hestia-Exit-Code: 0');
iptables -N HESTIA
class HestiaApp {}
EOF
    commit_fixtures
    apply
    assert_success
    run cat bin/v-case
    assert_line 'check_arcpanel_demo_mode'
    assert_line "header('ArcPanel-Exit-Code: 0');"
    assert_line 'iptables -N ARCPANEL'
    assert_line 'class ArcPanelApp {}'
}

@test "keep.txt strings, scoped keys and files are not rewritten" {
    cat > func/keep.sh << 'EOF'
# ArcPanel · Based on HestiaCP
# See https://github.com/hestiacp/hestiacp/issues/1234 and https://hestiacp.com/docs/
plugin=change-password-hestia
version=7.14.6-hestiacp
EOF
    cat > install/common/snappymail/install.php << 'EOF'
"hestia_host" => gethostname(),
"driver_hestia_enabled" => true,
EOF
    cat > bin/v-change-sys-hostname << 'EOF'
sed -i "/password_hestia_host/c\x" roundcube.php
sed -i "/\"hestia_host\":/c\x" snappymail.json
EOF
    echo 'hestia' > install/deb/pga/phppgadmin/vendored.php
    commit_fixtures
    apply
    assert_success
    assert_equal "$(sed -n 1p func/keep.sh)" '# ArcPanel · Based on HestiaCP'
    assert_equal "$(sed -n 2p func/keep.sh)" '# See https://github.com/hestiacp/hestiacp/issues/1234 and https://hestiacp.com/docs/'
    assert_equal "$(sed -n 3p func/keep.sh)" 'plugin=change-password-hestia'
    assert_equal "$(sed -n 4p func/keep.sh)" 'version=7.14.6-hestiacp'
    run cat install/common/snappymail/install.php
    assert_line '"hestia_host" => gethostname(),'
    assert_line '"driver_hestia_enabled" => true,'
    run cat bin/v-change-sys-hostname
    assert_line 'sed -i "/password_arcpanel_host/c\x" roundcube.php'
    assert_line 'sed -i "/\"hestia_host\":/c\x" snappymail.json'
    assert_equal "$(cat install/deb/pga/phppgadmin/vendored.php)" 'hestia'
}

@test "binary files are renamed but not rewritten" {
    printf 'hestiacp\0hestia\n' > lib/locale/de/LC_MESSAGES/hestiacp.mo
    commit_fixtures
    apply
    assert_success
    assert_file_not_exists lib/locale/de/LC_MESSAGES/hestiacp.mo
    assert_equal "$(od -An -c lib/locale/de/LC_MESSAGES/arcpanel.mo | tr -s ' ')" \
        "$(printf 'hestiacp\0hestia\n' | od -An -c | tr -s ' ')"
}

@test "files are renamed with git mv" {
    echo 'x' > bin/v-list-sys-hestia-ssl
    echo 'x' > install/hst-install-ubuntu.sh
    echo 'x' > src/hst_autocompile.sh
    commit_fixtures
    apply
    assert_success
    assert_output --partial 'rename bin/v-list-sys-hestia-ssl -> bin/v-list-sys-arcpanel-ssl'
    run git status --short
    assert_line --regexp '^R  bin/v-list-sys-hestia-ssl -> bin/v-list-sys-arcpanel-ssl$'
    assert_line --regexp '^R  install/hst-install-ubuntu.sh -> install/arcpanel-install-ubuntu.sh$'
    assert_line --regexp '^R  src/hst_autocompile.sh -> src/arcpanel-build.sh$'
}

@test "line endings and file modes are kept" {
    printf '#!/bin/bash\r\necho hestia\r\necho HESTIA\r\n' > bin/v-crlf
    chmod 755 bin/v-crlf
    commit_fixtures
    apply
    assert_success
    assert_equal "$(od -An -c bin/v-crlf | tr -s ' ')" \
        "$(printf '#!/bin/bash\r\necho arcpanel\r\necho ARCPANEL\r\n' | od -An -c | tr -s ' ')"
    assert_file_executable bin/v-crlf
}

@test "--dry-run lists the changes and writes nothing" {
    echo 'hestia' > bin/v-list-sys-hestia-ssl
    commit_fixtures
    apply --dry-run
    assert_success
    assert_output --partial 'rename bin/v-list-sys-hestia-ssl -> bin/v-list-sys-arcpanel-ssl'
    assert_output --partial 'edit   bin/v-list-sys-arcpanel-ssl (1 lines)'
    run git status --porcelain
    assert_output ''
}

@test "PATH arguments limit the run" {
    echo 'hestia' > bin/v-a
    echo 'hestia' > func/b.sh
    commit_fixtures
    apply bin
    assert_success
    assert_equal "$(cat bin/v-a)" 'arcpanel'
    assert_equal "$(cat func/b.sh)" 'hestia'
}

@test "a guard refuses the run and nothing changes" {
    echo 'HESTIA_VERSION=1' > func/guarded.sh
    echo 'hestia' > bin/v-other
    commit_fixtures
    apply
    assert_failure 1
    assert_output --partial 'guard  func/guarded.sh:1: HESTIA_VERSION'
    run git status --porcelain
    assert_output ''
}

@test "an existing rename target refuses the run" {
    echo 'old' > bin/v-update-sys-hestia
    echo 'wrapper' > bin/v-update-sys-arcpanel
    commit_fixtures
    apply
    assert_failure 1
    assert_output --partial 'clash  bin/v-update-sys-hestia -> bin/v-update-sys-arcpanel: target exists'
    assert_equal "$(cat bin/v-update-sys-arcpanel)" 'wrapper'
}

@test "--review lists changed lines with sed or a regular expression" {
    cat > bin/v-review << 'EOF'
sed -i "s|/usr/local/hestia|x|" file
echo /usr/local/hestia
grep -q hestia file
EOF
    commit_fixtures
    apply --review "$BATS_TEST_TMPDIR/review.txt"
    assert_success
    run cat "$BATS_TEST_TMPDIR/review.txt"
    assert_line 'bin/v-review:1: sed -i "s|/usr/local/arcpanel|x|" file'
    assert_line 'bin/v-review:3: grep -q arcpanel file'
    refute_line --partial 'bin/v-review:2:'
}
