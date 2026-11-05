#!/bin/bash
# E17 check web.ssl-expiry (spec §4.2): the served certificate expires
# within 14 days (warn) or 3 days (fail).
# shellcheck disable=SC2034
DOCTOR_ID=web.ssl-expiry
DOCTOR_SCOPE=web
DOCTOR_TITLE='The certificate does not expire soon'
DOCTOR_DOCS='/docs/guides/doctor#web-ssl-expiry'
DOCTOR_FIX_ALLOWED='v-add-letsencrypt-domain'

doctor_check() {
	local user="$1" domain="$2" line days end
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
	days=$(doctor_days_left "$CERT_END")
	end=$(date -d "@$CERT_END" +%F)
	if [ "$CERT_END" -le "$(doctor_now)" ]; then
		dr_fail "The certificate of $domain expired on $end." "$end"
	elif [ "$days" -lt 3 ]; then
		dr_fail "The certificate of $domain expires in $days days ($end)." "$end"
	elif [ "$days" -lt 14 ]; then
		dr_warn "The certificate of $domain expires in $days days ($end)." "$end"
	else
		dr_ok "The certificate of $domain is valid for $days more days." "$end"
		return
	fi
	dr_fix "Request a Let's Encrypt certificate" v-add-letsencrypt-domain "$user" "$domain" "$(doctor_le_aliases "$line")"
}
