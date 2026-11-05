#!/bin/bash
# E17 check web.ssl-valid (spec §4.2): with SSL on, the certificate the
# server serves for the domain is trusted, not expired, with its chain.
# shellcheck disable=SC2034
DOCTOR_ID=web.ssl-valid
DOCTOR_SCOPE=web
DOCTOR_TITLE='The certificate is valid'
DOCTOR_DOCS='/docs/guides/doctor#web-ssl-valid'
DOCTOR_FIX_ALLOWED='v-add-letsencrypt-domain'

doctor_check() {
	local user="$1" domain="$2" line
	line=$(doctor_domain_line "$user" web "$domain")
	if [ "$(doctor_field "$line" SSL)" != 'yes' ]; then
		dr_skip 'SSL is off for the domain.'
		return
	fi
	if ! doctor_domain_cert "$domain" "$(doctor_field "$line" IP)"; then
		dr_skip "The server did not answer HTTPS for $domain."
		return
	fi
	doctor_cert_fields <<< "$CERT_PEM"
	if [ "${DOCTOR_VERIFY%% *}" = '0' ] && [ "$CERT_END" -gt "$(doctor_now)" ]; then
		dr_ok "The certificate of $domain is trusted until $(date -d "@$CERT_END" +%F)." "$CERT_NAMES"
		return
	fi
	if [ "$CERT_END" -le "$(doctor_now)" ]; then
		dr_fail "The certificate of $domain expired on $(date -d "@$CERT_END" +%F)." "$CERT_NAMES"
	else
		dr_fail "Browsers do not trust the certificate of $domain: $(doctor_verify_text "$DOCTOR_VERIFY")." "$CERT_NAMES"
	fi
	dr_fix "Request a Let's Encrypt certificate" v-add-letsencrypt-domain "$user" "$domain" "$(doctor_le_aliases "$line")"
}
