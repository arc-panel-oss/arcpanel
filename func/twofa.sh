#!/bin/bash
# ArcPanel: two-factor authentication of panel accounts (E39 W5 spec §11,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md).
#
# user.conf keeps the TOTP secret (TWOFA), the time step of the last code
# accepted (TWOFA_STEP) and the SHA-256 hashes of the unused recovery codes
# (TWOFA_RECOVERY, comma list). An enrolment that is not confirmed yet lives
# in data/users/USER/2fa-pending (root, 0600) for TWOFA_PENDING_TTL seconds.

TWOFA_PENDING_TTL=600
TWOFA_RECOVERY_COUNT=10

twofa_pending_file() {
	echo "$ARCPANEL/data/users/$1/2fa-pending"
}

# A new secret: 160 random bits in base32 (RFC 4226 recommends 160)
twofa_new_secret() {
	head -c 20 /dev/urandom | base32 | tr -d '=\n'
}

# The otpauth URI of SECRET for USER, which authenticator apps read
twofa_uri() {
	local user="$1" secret="$2" label
	label=$(jq -rn --arg l "ArcPanel:$user@$(hostname -f 2> /dev/null || hostname)" '$l | @uri')
	echo "otpauth://totp/$label?secret=$secret&issuer=ArcPanel&algorithm=SHA1&digits=6&period=30"
}

# The secret of a pending enrolment of USER that has not expired, or nothing
twofa_pending_secret() {
	local file created secret
	file=$(twofa_pending_file "$1")
	[ -f "$file" ] || return 0
	created=$(grep -m1 '^CREATED=' "$file" | cut -d"'" -f2)
	secret=$(grep -m1 '^SECRET=' "$file" | cut -d"'" -f2)
	if [[ "$created" =~ ^[0-9]+$ ]] && [ $(($(date +%s) - created)) -le "$TWOFA_PENDING_TTL" ] \
		&& [[ "$secret" =~ ^[A-Z2-7]{32}$ ]]; then
		echo "$secret"
	fi
}

# A recovery code as the user types it: upper case, without spaces or dashes
twofa_recovery_normalize() {
	echo "$1" | tr '[:lower:]' '[:upper:]' | tr -d ' \t\r\n-'
}

# SHA-256 of a normalized recovery code
twofa_recovery_hash() {
	printf '%s' "$1" | sha256sum | cut -d' ' -f1
}

# Replace the recovery codes of USER with TWOFA_RECOVERY_COUNT new ones and
# print them, one per line: 16 characters of the base32 alphabet (80 bits)
# in groups of four. Only their hashes are kept.
twofa_recovery_new() {
	local user="$1" i code hashes=''
	for ((i = 0; i < TWOFA_RECOVERY_COUNT; i++)); do
		code=$(head -c 10 /dev/urandom | base32 | tr -d '=\n')
		hashes="${hashes:+$hashes,}$(twofa_recovery_hash "$code")"
		echo "${code:0:4}-${code:4:4}-${code:8:4}-${code:12:4}"
	done
	set_user_value "$user" '$TWOFA_RECOVERY' "$hashes"
}

# Number of unused recovery codes of USER
twofa_recovery_left() {
	local list
	list=$(grep -m1 "^TWOFA_RECOVERY=" "$ARCPANEL/data/users/$1/user.conf" 2> /dev/null | cut -d"'" -f2)
	if [ -z "$list" ]; then
		echo 0
	else
		echo "$list" | tr ',' '\n' | grep -c .
	fi
}

# Print the recovery codes on stdout in FORMAT (shell or json)
twofa_recovery_print() {
	local format="$1"
	if [ "$format" = 'json' ]; then
		jq -R . | jq -s '{RECOVERY_CODES: .}'
	else
		cat
	fi
}
