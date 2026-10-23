#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel audit log (E23): one append-only, hash-chained JSONL file per    #
# UTC day in data/audit/, root only, written and read through               #
# func/audit.php. See                                                       #
# docs/features/specs/2026-10-23-002-e23-audit-log-design.md.               #
#                                                                           #
#===========================================================================#

AUDIT_DIR="$ARCPANEL/data/audit"
AUDIT_HELPER="$ARCPANEL/func/audit.php"
AUDIT_DEFAULT_RETENTION=180

# Create data/audit/ (root:root 0700)
audit_dir_init() {
	mkdir -p "$AUDIT_DIR"
	chown root:root "$AUDIT_DIR"
	chmod 700 "$AUDIT_DIR"
}

# The retention in days: AUDIT_RETENTION_DAYS when valid, else the default
audit_retention() {
	if [[ "${AUDIT_RETENTION_DAYS-}" =~ ^[0-9]{1,4}$ ]] && [ "$AUDIT_RETENTION_DAYS" -ge 7 ] && [ "$AUDIT_RETENTION_DAYS" -le 3650 ]; then
		echo "$AUDIT_RETENTION_DAYS"
	else
		echo "$AUDIT_DEFAULT_RETENTION"
	fi
}

# The first UTC day the retention keeps
audit_keep_from() {
	date -u -d "$(audit_retention) days ago" +%F
}

# A day as YYYY-MM-DD that exists
audit_check_day() {
	if ! [[ "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || [ "$(date -u -d "$1" +%F 2> /dev/null)" != "$1" ]; then
		check_result "$E_INVALID" "invalid $2 :: $1 (YYYY-MM-DD)"
	fi
}

# The event file of the panel: a regular file, not a link, owned by the
# account that called sudo (root otherwise), so that the command never reads
# or removes a file that its caller could not
audit_check_event_file() {
	local file="$1" owner
	if [ -z "$file" ] || [ -L "$file" ] || [ ! -f "$file" ]; then
		check_result "$E_NOTEXIST" "the event file does not exist"
	fi
	owner=$(stat -c %U -- "$file")
	if [ "$owner" != "${SUDO_USER:-root}" ] && [ "$owner" != 'root' ]; then
		check_result "$E_FORBIDEN" "the event file belongs to another account"
	fi
}

# Send a written line to the local syslog when AUDIT_SYSLOG is yes (D8)
audit_syslog() {
	if [ "${AUDIT_SYSLOG-}" = 'yes' ] && [ -n "$1" ] && command -v logger > /dev/null 2>&1; then
		logger --size 8192 -t arcpanel-audit -p auth.info -- "$1" 2> /dev/null || true
	fi
}

# Run func/audit.php with ARGS: stdout in AUDIT_OUT, the exit code in
# AUDIT_RC and the first 300 bytes of stderr in AUDIT_MSG
audit_php() {
	local err
	err=$(mktemp)
	AUDIT_OUT=$("$ARCPANEL_PHP" "$AUDIT_HELPER" "$@" 2> "$err")
	AUDIT_RC=$?
	AUDIT_MSG=$(head -c 300 "$err")
	rm -f "$err"
}

# Exit with the command's code for the AUDIT_RC of func/audit.php
audit_check_rc() {
	case "$AUDIT_RC" in
		0) return 0 ;;
		2) check_result "$E_INVALID" "${AUDIT_MSG:-invalid audit event}" ;;
		13) check_result "$E_DISK" "${AUDIT_MSG:-the audit log cannot be written}" ;;
		*) check_result "$E_PARSING" "${AUDIT_MSG:-the audit log cannot be read}" ;;
	esac
}

# Append the event of FILE; exits on an invalid event
audit_append_file() {
	audit_dir_init
	audit_php append "$AUDIT_DIR" "$1"
	audit_check_rc
	audit_syslog "$AUDIT_OUT"
}

# Record an event of channel system (audit.start, audit.purge) with NAME as
# the object's name
audit_system_event() {
	audit_dir_init
	audit_php system "$AUDIT_DIR" "$1" "$2"
	audit_check_rc
	audit_syslog "$AUDIT_OUT"
}

# The users of OWNER's tree, comma separated: OWNER and, for a reseller, its
# customers
audit_tree() {
	local owner="$1"
	{
		echo "$owner"
		tenancy_customers "$owner"
	} | paste -sd, -
}
