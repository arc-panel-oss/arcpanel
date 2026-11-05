#!/bin/bash
# E17 check mail.dkim (spec §4.3): DKIM is on for the domain and the key
# published at mail._domainkey is the domain's key
# (v-list-mail-domain-dkim-dns).
# shellcheck disable=SC2034
DOCTOR_ID=mail.dkim
DOCTOR_SCOPE=mail
DOCTOR_TITLE='DKIM signs the mail of the domain'
DOCTOR_DOCS='/docs/guides/doctor#mail-dkim'
DOCTOR_FIX_ALLOWED='v-add-mail-domain-dkim'

doctor_check() {
	local user="$1" domain="$2" line key txt published
	line=$(doctor_domain_line "$user" mail "$domain")
	if [ "$(doctor_field "$line" DKIM)" != 'yes' ]; then
		dr_fail "DKIM is off for $domain, so its mail is not signed and is more likely to be marked as spam." off
		dr_fix 'Turn on DKIM' v-add-mail-domain-dkim "$user" "$domain"
		return
	fi
	doctor_dns_zone "$user" "$domain" > /dev/null && DR_LINK=dns
	key=$(grep -v 'KEY-----' "$ARCPANEL/data/users/$user/mail/$domain.pub" 2> /dev/null | tr -d '\n\r ')
	if [ -z "$key" ]; then
		dr_skip 'The DKIM key of the domain could not be read.'
		return
	fi
	if ! txt=$(doctor_dig TXT "mail._domainkey.$domain"); then
		dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
		return
	fi
	published=$(grep -oE '(^|;)[[:space:]]*p=[^;]*' <<< "$txt" | head -n1 | sed -E 's/^;?[[:space:]]*p=//' | tr -d ' ')
	if [ -z "$published" ]; then
		dr_warn "The DKIM key of $domain is not published. Publish the TXT record mail._domainkey.$domain shown on the mail domain's DNS page." "mail._domainkey.$domain"
		return
	fi
	if [ "$published" != "$key" ]; then
		dr_warn "The DKIM key published at mail._domainkey.$domain is not the key of this server, so signatures fail. Publish the current key." "mail._domainkey.$domain"
		return
	fi
	dr_ok "DKIM is on and its key is published at mail._domainkey.$domain." "mail._domainkey.$domain"
}
