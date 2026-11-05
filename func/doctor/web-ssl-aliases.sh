#!/bin/bash
# E17 check web.ssl-aliases (spec §4.2): the served certificate covers the
# domain and every alias.
# shellcheck disable=SC2034
DOCTOR_ID=web.ssl-aliases
DOCTOR_SCOPE=web
DOCTOR_TITLE='The certificate covers every alias'
DOCTOR_DOCS='/docs/guides/doctor#web-ssl-aliases'
DOCTOR_FIX_ALLOWED='v-add-letsencrypt-domain'

doctor_check() {
	local user="$1" domain="$2" line name missing='' wildcard=no
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
	for name in "${domain,,}" $(doctor_aliases "$line" yes); do
		if ! doctor_cert_covers "$name" "$CERT_NAMES"; then
			missing="$missing, $name"
			[[ "$name" == \** ]] && wildcard=yes
		fi
	done
	if [ -z "$missing" ]; then
		dr_ok "The certificate covers $domain and its aliases." "$CERT_NAMES"
		return
	fi
	missing=${missing#, }
	if [[ "$missing" == *,* ]]; then
		dr_fail "$missing are not in the certificate." "$CERT_NAMES"
	else
		dr_fail "$missing is not in the certificate." "$CERT_NAMES"
	fi
	# Let's Encrypt over HTTP cannot issue a wildcard name
	if [ "$wildcard" = 'no' ]; then
		dr_fix "Request a Let's Encrypt certificate" v-add-letsencrypt-domain "$user" "$domain" "$(doctor_le_aliases "$line")"
	fi
}
