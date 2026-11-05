#!/bin/bash
# E17 check web.dns-aaaa (spec §4.2): with IPv6 on the domain, its AAAA
# record points to that address.
# shellcheck disable=SC2034
DOCTOR_ID=web.dns-aaaa
DOCTOR_SCOPE=web
DOCTOR_TITLE='The IPv6 record points to this server'
DOCTOR_DOCS='/docs/guides/doctor#web-dns-aaaa'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local user="$1" domain="$2" line ip6 answers
	line=$(doctor_domain_line "$user" web "$domain")
	ip6=$(doctor_field "$line" IP6)
	ip6=${ip6,,}
	if [ -z "$ip6" ]; then
		dr_skip 'The domain has no IPv6 address.'
		return
	fi
	doctor_dns_zone "$user" "$domain" > /dev/null && DR_LINK=dns
	if ! answers=$(doctor_dig AAAA "$domain"); then
		dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
		return
	fi
	if [ -z "$answers" ]; then
		dr_warn "$domain has no AAAA record, so it is not reachable over IPv6. Publish an AAAA record with the address $ip6." "$ip6"
		return
	fi
	if ! grep -qxF "$ip6" <<< "$answers"; then
		dr_warn "The AAAA record of $domain points to $(paste -sd, - <<< "$answers"), not to $ip6." "$ip6"
		return
	fi
	dr_ok "The AAAA record of $domain points to $ip6." "$ip6"
}
