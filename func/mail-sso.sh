#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel webmail single sign-on functions (E46)                           #
#                                                                           #
# A sign-on starts with a ticket: 32 random bytes, valid 60 seconds, used   #
# once and bound to the browser's address, stored by its SHA-256 in         #
#   $ARCPANEL/data/mail-sso/<sha256>                                        #
# Roundcube exchanges it on the loopback address for a mailbox session      #
# password (32 random bytes, 8 hours), whose SHA512-CRYPT hash is the line  #
# of the address in the second Dovecot passdb                               #
#   /etc/dovecot/arcpanel-sso.passwd                                        #
# address:{SHA512-CRYPT}hash:::EXPIRY:::allow_nets=127.0.0.1/32,::1/128     #
# The expiry sits in the unused GECOS field; only ArcPanel reads it. One    #
# line per address: a new sign-on replaces the previous one.                #
# Callers source func/main.sh first.                                        #
# See docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md. #
#                                                                           #
#===========================================================================#

MAIL_SSO_DIR="$ARCPANEL/data/mail-sso"
MAIL_SSO_PASSDB="/etc/dovecot/arcpanel-sso.passwd"
MAIL_SSO_TICKET_TTL=60
MAIL_SSO_SESSION_TTL=28800
MAIL_SSO_NETS='127.0.0.1/32,::1/128'

# 32 random bytes as base64url without padding (43 characters)
mail_sso_random() {
	openssl rand -base64 32 | tr '+/' '-_' | tr -d '=\n'
}

mail_sso_sha256() {
	printf '%s' "$1" | sha256sum | cut -d' ' -f1
}

# Serialise every writer of the passdb and the tickets
mail_sso_lock() {
	mkdir -p "$MAIL_SSO_DIR"
	chmod 700 "$MAIL_SSO_DIR"
	exec {MAIL_SSO_LOCK_FD}> "$MAIL_SSO_DIR/.lock"
	if ! flock -w 30 "$MAIL_SSO_LOCK_FD"; then
		check_result "$E_LA" "webmail sign-on is locked by another command"
	fi
}

# Write a ticket for ADDRESS of USER, bound to CLIENT_IP; prints the ticket
mail_sso_ticket_add() {
	local user="$1" address="$2" client_ip="$3" actor="$4" ticket file
	ticket=$(mail_sso_random)
	file="$MAIL_SSO_DIR/$(mail_sso_sha256 "$ticket")"
	(
		umask 077
		echo "USER='$user' ADDRESS='$address' CLIENT_IP='$client_ip' ACTOR='$actor' EXPIRES='$(($(date +%s) + MAIL_SSO_TICKET_TTL))'" > "$file"
	)
	echo "$ticket"
}

# Consume TICKET: delete its record whatever the result, and set
# SSO_USER, SSO_ADDRESS, SSO_CLIENT_IP, SSO_ACTOR, SSO_EXPIRES (return 1 when
# there is no such ticket)
mail_sso_ticket_take() {
	local ticket="$1" file line
	[[ "$ticket" =~ ^[A-Za-z0-9_-]{43}$ ]] || return 1
	file="$MAIL_SSO_DIR/$(mail_sso_sha256 "$ticket")"
	[ -f "$file" ] && [ ! -L "$file" ] || return 1
	line=$(head -n 1 "$file")
	rm -f "$file"
	SSO_USER=$(echo "$line" | grep -o "USER='[^']*'" | head -n1 | cut -d"'" -f2)
	SSO_ADDRESS=$(echo "$line" | grep -o "ADDRESS='[^']*'" | cut -d"'" -f2)
	SSO_CLIENT_IP=$(echo "$line" | grep -o "CLIENT_IP='[^']*'" | cut -d"'" -f2)
	SSO_ACTOR=$(echo "$line" | grep -o "ACTOR='[^']*'" | cut -d"'" -f2)
	SSO_EXPIRES=$(echo "$line" | grep -o "EXPIRES='[^']*'" | cut -d"'" -f2)
	[ -n "$SSO_USER" ] && [ -n "$SSO_ADDRESS" ]
}

# Make sure the passdb exists with its owner and mode
mail_sso_passdb_init() {
	if [ ! -f "$MAIL_SSO_PASSDB" ]; then
		touch "$MAIL_SSO_PASSDB"
	fi
	chown root:dovecot "$MAIL_SSO_PASSDB" 2> /dev/null || chown root:root "$MAIL_SSO_PASSDB"
	chmod 640 "$MAIL_SSO_PASSDB"
}

# Rewrite the passdb without the lines that the awk program PROGRAM drops
# (it prints the lines to keep), then append LINE when given
mail_sso_passdb_rewrite() {
	local program="$1" line="$2" tmp
	mail_sso_passdb_init
	tmp=$(mktemp "$MAIL_SSO_PASSDB.XXXXXX")
	awk -F: "$program" "$MAIL_SSO_PASSDB" > "$tmp"
	if [ -n "$line" ]; then
		echo "$line" >> "$tmp"
	fi
	chown --reference="$MAIL_SSO_PASSDB" "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$MAIL_SSO_PASSDB"
}

# Create the session password of ADDRESS (replacing the previous one) and
# print it
mail_sso_session_add() {
	local address="$1" password hash expiry
	password=$(mail_sso_random)
	hash=$(printf '%s' "$password" | openssl passwd -6 -stdin)
	expiry=$(($(date +%s) + MAIL_SSO_SESSION_TTL))
	mail_sso_passdb_rewrite "\$1 != \"$address\"" "$address:{SHA512-CRYPT}$hash:::$expiry:::allow_nets=$MAIL_SSO_NETS"
	echo "$password"
}

# Remove the session of ACCOUNT@DOMAIN, or of every account of DOMAIN
mail_sso_session_delete() {
	local domain="$1" account="$2"
	[ -f "$MAIL_SSO_PASSDB" ] || return 0
	# Names are checked by the callers (is_format_valid): no quotes in them
	if [ -n "$account" ]; then
		mail_sso_passdb_rewrite "\$1 != \"$account@$domain\""
	else
		mail_sso_passdb_rewrite "substr(\$1, length(\$1) - ${#domain}) != \"@$domain\""
	fi
}

# Remove expired sessions and tickets
mail_sso_prune() {
	local now file expires
	now=$(date +%s)
	if [ -f "$MAIL_SSO_PASSDB" ]; then
		mail_sso_passdb_rewrite "\$5 > $now"
	fi
	for file in "$MAIL_SSO_DIR"/*; do
		[ -f "$file" ] || continue
		expires=$(grep -o "EXPIRES='[0-9]*'" "$file" | cut -d"'" -f2)
		if [ -z "$expires" ] || [ "$expires" -le "$now" ]; then
			rm -f "$file"
		fi
	done
}

# Revoke the sessions of ACCOUNT@DOMAIN (or of DOMAIN) from another command;
# never fails the caller
mail_sso_revoke() {
	if [ -x "$BIN/v-delete-mail-account-sso" ]; then
		"$BIN/v-delete-mail-account-sso" "$@" > /dev/null 2>&1 || true
	fi
}
