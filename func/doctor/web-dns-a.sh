#!/bin/bash
# E17 check web.dns-a (spec §4.2): the A record of the domain and of each
# alias resolves to the domain's address (its NAT address when it has one);
# an address of a CDN proxy warns (spec §7).
# shellcheck disable=SC2034
DOCTOR_ID=web.dns-a
DOCTOR_SCOPE=web
DOCTOR_TITLE='The domain and its aliases point to this server'
DOCTOR_DOCS='/docs/guides/doctor#web-dns-a'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local user="$1" domain="$2" line ip name answers bad='' proxied='' zone hint
	line=$(doctor_domain_line "$user" web "$domain")
	ip=$(doctor_public_ip "$(doctor_field "$line" IP)")
	if zone=$(doctor_dns_zone "$user" "$domain"); then
		DR_LINK=dns
	fi
	for name in "$domain" $(doctor_aliases "$line"); do
		if ! answers=$(doctor_dig A "$name"); then
			dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
			return
		fi
		if [ -z "$answers" ]; then
			bad="$bad $name (no A record)"
		elif grep -qxF "$ip" <<< "$answers"; then
			continue
		elif ! grep -v '^$' <<< "$answers" | while read -r a; do doctor_ip_is_proxy "$a" || exit 1; done; then
			bad="$bad $name ($(paste -sd, - <<< "$answers"))"
		else
			proxied="$proxied $name"
		fi
	done
	if [ -n "$bad" ]; then
		if [ -n "$zone" ]; then
			hint="Change the A records in the DNS zone $zone on this server."
		else
			hint="Publish an A record with the address $ip for each of them at your DNS provider."
		fi
		dr_fail "These names do not point to $ip:${bad}. $hint" "$ip"
		return
	fi
	if [ -n "$proxied" ]; then
		dr_warn "These names resolve to a proxy (CDN), so the address of the server cannot be checked:${proxied}." "$ip"
		return
	fi
	dr_ok "The domain and its aliases point to $ip." "$ip"
}
