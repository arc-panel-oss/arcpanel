#!/bin/bash
# E17 check server.panel-ssl (spec §4.1): the panel certificate is valid
# and does not expire within 7 days; a self-signed certificate warns.
# shellcheck disable=SC2034
DOCTOR_ID=server.panel-ssl
DOCTOR_SCOPE=server
DOCTOR_TITLE='The panel certificate is valid'
DOCTOR_DOCS='/docs/guides/doctor#server-panel-ssl'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local file="$ARCPANEL/ssl/certificate.crt" pem tmp days
	DR_LINK=panel-ssl
	if ! pem=$(doctor_src panel-cert cat "$file" 2> /dev/null) || ! grep -q 'BEGIN CERTIFICATE' <<< "$pem"; then
		dr_fail 'The panel has no certificate.' ''
		return
	fi
	doctor_cert_fields <<< "$pem"
	days=$(doctor_days_left "$CERT_END")
	if [ "$CERT_END" -le "$(doctor_now)" ]; then
		dr_fail "The panel certificate expired on $(date -d "@$CERT_END" +%F)." "$CERT_NAMES"
		return
	fi
	if [ "$days" -lt 7 ]; then
		dr_fail "The panel certificate expires in $days days ($(date -d "@$CERT_END" +%F))." "$CERT_NAMES"
		return
	fi
	if [ "$CERT_SUBJECT" = "$CERT_ISSUER" ]; then
		dr_warn 'The panel uses a self-signed certificate, so browsers warn on every visit. Request a Let'"'"'s Encrypt certificate in Server › Settings › SSL.' "$CERT_NAMES"
		return
	fi
	tmp=$(mktemp)
	printf '%s\n' "$pem" > "$tmp"
	doctor_verify_file "$tmp"
	rm -f "$tmp"
	if [ "${DOCTOR_VERIFY%% *}" != '0' ]; then
		dr_fail "The panel certificate is not trusted: ${DOCTOR_VERIFY#* }." "$CERT_NAMES"
		return
	fi
	dr_ok "The panel certificate is valid for $days more days." "$CERT_NAMES"
}
