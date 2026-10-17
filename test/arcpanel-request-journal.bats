#!/usr/bin/env bats
# Retention of the REST API v2 request journal (E43 M4, B4):
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §7.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
JOURNAL=$ARCPANEL/web-app/storage/app/api-requests

setup() {
	CREATED=()
	if [ ! -d "$JOURNAL" ]; then
		install -d -m 0700 -o arcpanelweb -g arcpanelweb "$JOURNAL"
		CREATED_DIR=yes
	fi
}

teardown() {
	local file
	for file in "${CREATED[@]}"; do
		rm -f "$file"
	done
	if [ "${CREATED_DIR:-}" = yes ]; then
		rmdir "$JOURNAL" 2> /dev/null || true
	fi
}

# journal_file NAME -> FILE is the path; creates the file when it is missing and
# records it for teardown (not in a command substitution, which is a subshell)
journal_file() {
	FILE="$JOURNAL/$1"
	if [ ! -e "$FILE" ]; then
		echo '{"id":"arcbats"}' > "$FILE"
		CREATED+=("$FILE")
	fi
}

@test "v-update-sys-queue daily removes the journal days older than 7 days and keeps the rest" {
	local old older week today other
	journal_file 2001-01-01.jsonl
	old=$FILE
	journal_file "$(date -d '-8 days' +%F).jsonl"
	older=$FILE
	journal_file "$(date -d '-7 days' +%F).jsonl"
	week=$FILE
	journal_file "$(date +%F).jsonl"
	today=$FILE
	journal_file arcbats-notes.txt
	other=$FILE

	run v-update-sys-queue daily
	assert_success
	[ ! -e "$old" ] || fail "$old is still there"
	[ ! -e "$older" ] || fail "$older is still there"
	[ -e "$week" ] || fail "$week was removed"
	[ -e "$today" ] || fail "$today was removed"
	[ -e "$other" ] || fail "$other was removed"
}

@test "v-update-sys-queue daily works without a journal" {
	# The journal of the server is set aside for the case
	mv "$JOURNAL" "$JOURNAL.arcbats"
	run v-update-sys-queue daily
	mv "$JOURNAL.arcbats" "$JOURNAL"
	assert_success
}
