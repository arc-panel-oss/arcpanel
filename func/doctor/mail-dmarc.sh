#!/bin/bash
# E17 check mail.dmarc (spec §4.3): the domain publishes a _dmarc record.
# shellcheck disable=SC2034
DOCTOR_ID=mail.dmarc
DOCTOR_SCOPE=mail
DOCTOR_TITLE='The domain has a DMARC policy'
DOCTOR_DOCS='/docs/guides/doctor#mail-dmarc'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local user="$1" domain="$2" txt record
	doctor_dns_zone "$user" "$domain" > /dev/null && DR_LINK=dns
	if ! txt=$(doctor_dig TXT "_dmarc.$domain"); then
		dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
		return
	fi
	record=$(grep -iE '^v=DMARC1' <<< "$txt" | head -n1)
	if [ -z "$record" ]; then
		dr_warn "$domain has no DMARC record. Publish a TXT record _dmarc.$domain such as \"v=DMARC1; p=quarantine\"." ''
		return
	fi
	dr_ok "$domain has a DMARC policy." "$record"
}
