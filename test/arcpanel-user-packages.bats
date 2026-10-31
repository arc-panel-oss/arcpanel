#!/usr/bin/env bats
# v-list-user-packages: the [FORMAT] list of the hosting packages in
# $ARCPANEL/data/packages, in the plain, json and shell formats. A test
# package with known quotas is added so that one full row of every format
# can be checked against the package file on disk.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
TEST_PKG=arcbats-plan

# A package with every quota key finite, built from default.pkg the same
# way test/arcpanel-sys-lists.bats builds its fixture packages
make_package() {
	local name=$1 amount=$2 tmp
	tmp=$(mktemp)
	sed -e "s/^\(WEB_DOMAINS\|DNS_DOMAINS\|MAIL_DOMAINS\|MAIL_ACCOUNTS\|DATABASES\|CRON_JOBS\)=.*/\1='$amount'/" \
		-e "s/^DISK_QUOTA=.*/DISK_QUOTA='$((amount * 1000))'/" \
		-e "s/^BANDWIDTH=.*/BANDWIDTH='$((amount * 1000))'/" \
		"$PKG_DIR/default.pkg" > "$tmp"
	v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
	rm -f "$tmp"
}

cleanup() {
	v-delete-user-package "$TEST_PKG" > /dev/null 2>&1 || true
	rm -f "$PKG_DIR/$TEST_PKG.tenancy"
}

setup_file() {
	cleanup
	make_package "$TEST_PKG" 7
}

teardown_file() {
	cleanup
}

# The packages the command lists, computed the same way it computes them:
# the .pkg files of the package dir, newest first, names without .pkg
packages() {
	local package
	for package in $(ls --sort=time "$PKG_DIR" | grep .pkg); do
		echo "${package/.pkg/}"
	done
}

# One field of a package, read from its file on disk
pkg_field() {
	sed -n "s/^$2='\?\([^']*\)'\?$/\1/p" "$PKG_DIR/$1.pkg"
}

# The plain row of a package, built from its file on disk in the field
# order plain_list prints
plain_row() {
	local package=$1 field out=""
	for field in WEB_TEMPLATE PROXY_TEMPLATE DNS_TEMPLATE WEB_DOMAINS \
		WEB_ALIASES DNS_DOMAINS DNS_RECORDS MAIL_DOMAINS MAIL_ACCOUNTS \
		DATABASES CRON_JOBS DISK_QUOTA CPU_QUOTA CPU_QUOTA_PERIOD \
		MEMORY_LIMIT SWAP_LIMIT BANDWIDTH NS SHELL BACKUPS TIME DATE; do
		out+=$'\t'"$(pkg_field "$package" "$field")"
	done
	echo "$package$out"
}

# The shell row of a package as single-spaced words, in the field order
# shell_list prints, with its unlimited to unlim shortening applied
shell_row() {
	local package=$1 field value out
	out=$package
	for field in WEB_TEMPLATE WEB_DOMAINS DNS_DOMAINS MAIL_DOMAINS \
		DATABASES SHELL DISK_QUOTA CPU_QUOTA CPU_QUOTA_PERIOD \
		MEMORY_LIMIT SWAP_LIMIT BANDWIDTH; do
		value=$(pkg_field "$package" "$field")
		out+=" ${value/unlimited/unlim}"
	done
	echo "$out"
}

@test "v-list-user-packages plain prints one tab separated row per package, newest first" {
	run v-list-user-packages plain
	assert_success
	assert_equal "$(echo "$output" | cut -f 1)" "$(packages)"
	assert_equal "$(echo "$output" | awk -F '\t' -v p="$TEST_PKG" '$1 == p')" "$(plain_row "$TEST_PKG")"
}

@test "v-list-user-packages without a format prints the shell format" {
	run v-list-user-packages
	assert_success
	assert_line --index 0 --regexp '^PKG +TPL +WEB +DNS +MAIL +DB +SHELL +DISK +CPU_QUOTA +CPU_QUOTA_PERIOD +MEMORY_LIMIT +SWAP_LIMIT +BW$'
	assert_line --index 1 --partial '---'
}

@test "v-list-user-packages shell prints the header, then one row per package" {
	run v-list-user-packages shell
	assert_success
	assert_line --index 0 --regexp '^PKG +TPL +WEB +DNS +MAIL +DB +SHELL +DISK +CPU_QUOTA +CPU_QUOTA_PERIOD +MEMORY_LIMIT +SWAP_LIMIT +BW$'
	assert_line --index 1 --partial '---'
	assert_equal "$(tail -n +3 <<< "$output" | awk '{print $1}')" "$(packages)"
	assert_equal "$(echo "$output" | awk -v p="$TEST_PKG" '$1 == p {$1 = $1; print}')" "$(shell_row "$TEST_PKG")"
}

@test "v-list-user-packages json prints every package with the values of its file" {
	run v-list-user-packages json
	assert_success
	echo "$output" | jq -e 'type == "object"' > /dev/null
	assert_equal "$(echo "$output" | jq -r 'keys[]')" "$(packages | sort)"
	assert_equal "$(echo "$output" | jq -c --arg p "$TEST_PKG" '.[$p] | [.WEB_TEMPLATE, .WEB_DOMAINS, .DATABASES, .DISK_QUOTA, .SHELL, .BACKUPS]')" \
		"[\"$(pkg_field "$TEST_PKG" WEB_TEMPLATE)\",\"7\",\"7\",\"7000\",\"$(pkg_field "$TEST_PKG" SHELL)\",\"$(pkg_field "$TEST_PKG" BACKUPS)\"]"
}
