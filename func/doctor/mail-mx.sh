#!/bin/bash
# E17 check mail.mx (spec §4.3): an MX record points to this server (its
# mail host or one of its addresses).
# shellcheck disable=SC2034
DOCTOR_ID=mail.mx
DOCTOR_SCOPE=mail
DOCTOR_TITLE='The MX record points to this server'
DOCTOR_DOCS='/docs/guides/doctor#mail-mx'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local user="$1" domain="$2" answers host addresses ips hosts='' a
	doctor_dns_zone "$user" "$domain" > /dev/null && DR_LINK=dns
	if ! answers=$(doctor_dig MX "$domain"); then
		dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
		return
	fi
	if [ -z "$answers" ]; then
		dr_fail "$domain has no MX record, so other servers cannot deliver mail to it. Publish an MX record for mail.$domain pointing to this server." ''
		return
	fi
	ips=$(doctor_server_ips)
	while read -r _ host; do
		[ -n "$host" ] || continue
		hosts="$hosts, $host"
		if [ "$host" = "$(doctor_hostname | tr 'A-Z' 'a-z')" ]; then
			dr_ok "The MX record $host is this server." "$host"
			return
		fi
		if ! addresses=$(doctor_resolve_a "$host"); then
			dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
			return
		fi
		for a in $addresses; do
			if grep -qxF "$a" <<< "$ips"; then
				dr_ok "The MX record $host points to this server ($a)." "$host"
				return
			fi
		done
	done <<< "$(sort -n <<< "$answers")"
	dr_fail "No MX record of $domain points to this server (${hosts#, }), so mail for it goes elsewhere." "${hosts#, }"
}
