#!/bin/bash
# E17 check mail.ssl (spec §4.3): the mail certificate of the domain is
# valid and covers mail.DOMAIN.
# shellcheck disable=SC2034
DOCTOR_ID=mail.ssl
DOCTOR_SCOPE=mail
DOCTOR_TITLE='The mail certificate is valid'
DOCTOR_DOCS='/docs/guides/doctor#mail-ssl'
DOCTOR_FIX_ALLOWED='v-add-letsencrypt-domain'

doctor_check() {
	local user="$1" domain="$2" line file ssl_dir
	line=$(doctor_domain_line "$user" mail "$domain")
	ssl_dir="$ARCPANEL/data/users/$user/ssl"
	file="$ssl_dir/mail.$domain.crt"
	if [ "$(doctor_field "$line" SSL)" != 'yes' ] || [ ! -f "$file" ]; then
		dr_fail "mail.$domain has no certificate, so mail clients warn or connect unencrypted." "mail.$domain"
		dr_fix "Request a Let's Encrypt certificate for mail" v-add-letsencrypt-domain "$user" "$domain" '' yes
		return
	fi
	doctor_cert_fields < "$file"
	doctor_verify_file "$file" "$ssl_dir/mail.$domain.ca"
	if [ "$CERT_END" -le "$(doctor_now)" ]; then
		dr_fail "The mail certificate of $domain expired on $(date -d "@$CERT_END" +%F)." "$CERT_NAMES"
	elif ! doctor_cert_covers "mail.$domain" "$CERT_NAMES"; then
		dr_fail "The mail certificate does not cover mail.$domain (it covers $CERT_NAMES)." "$CERT_NAMES"
	elif [ "${DOCTOR_VERIFY%% *}" != '0' ]; then
		dr_fail "Mail clients do not trust the mail certificate of $domain: $(doctor_verify_text "$DOCTOR_VERIFY")." "$CERT_NAMES"
	else
		dr_ok "The mail certificate covers mail.$domain and is trusted until $(date -d "@$CERT_END" +%F)." "$CERT_NAMES"
		return
	fi
	dr_fix "Request a Let's Encrypt certificate for mail" v-add-letsencrypt-domain "$user" "$domain" '' yes
}
