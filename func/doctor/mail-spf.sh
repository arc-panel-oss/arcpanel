#!/bin/bash
# E17 check mail.spf (spec §4.3): the domain publishes an SPF record that
# allows this server (ip4, a, mx or an include that does).
# shellcheck disable=SC2034
DOCTOR_ID=mail.spf
DOCTOR_SCOPE=mail
DOCTOR_TITLE='The SPF record allows this server'
DOCTOR_DOCS='/docs/guides/doctor#mail-spf'
DOCTOR_FIX_ALLOWED=''

# The SPF records of NAME, one per line
doctor_spf_records() {
	local txt
	txt=$(doctor_dig TXT "$1") || return 2
	grep -iE '^v=spf1( |$)' <<< "$txt"
}

# Whether SPF RECORD of NAME allows one of the server addresses (DEPTH
# limits the includes)
doctor_spf_allows() {
	local record="$1" name="$2" depth="$3" term mech value a host
	# ?all is a glob
	local -
	set -f
	for term in $record; do
		term=${term#+}
		mech=${term%%[:/]*}
		mech=${mech,,}
		value=''
		[[ "$term" == *:* ]] && value=${term#*:}
		case "$mech" in
			ip4)
				for a in $DOCTOR_SPF_IPS; do
					doctor_ip4_in "$a" "$value" && return 0
				done
				;;
			a)
				for a in $(doctor_resolve_a "${value:-$name}"); do
					grep -qxF "$a" <<< "$DOCTOR_SPF_IPS" && return 0
				done
				;;
			mx)
				while read -r _ host; do
					[ -n "$host" ] || continue
					for a in $(doctor_resolve_a "$host"); do
						grep -qxF "$a" <<< "$DOCTOR_SPF_IPS" && return 0
					done
				done <<< "$(doctor_dig MX "${value:-$name}")"
				;;
			include | redirect)
				[ "$depth" -gt 0 ] && [ -n "$value" ] || continue
				local sub
				sub=$(doctor_spf_records "$value" | head -n1)
				[ -n "$sub" ] && doctor_spf_allows "$sub" "$value" $((depth - 1)) && return 0
				;;
		esac
	done
	return 1
}

doctor_check() {
	local user="$1" domain="$2" records count rc
	doctor_dns_zone "$user" "$domain" > /dev/null && DR_LINK=dns
	records=$(doctor_spf_records "$domain")
	rc=$?
	if [ "$rc" -eq 2 ]; then
		dr_skip "$(doctor_resolver_text | sed 's/^t/T/') did not answer."
		return
	fi
	records=$(grep -v '^$' <<< "$records")
	if [ -z "$records" ]; then
		dr_fail "$domain has no SPF record, so other servers may treat its mail as spam. Publish a TXT record such as \"v=spf1 a mx ip4:$(doctor_main_ip) -all\"." ''
		return
	fi
	count=$(wc -l <<< "$records")
	if [ "$count" -gt 1 ]; then
		dr_warn "$domain has $count SPF records; receivers reject SPF when there is more than one. Merge them into one." "$(head -n1 <<< "$records")"
		return
	fi
	# "redirect=x" is a modifier: split it like a mechanism
	DOCTOR_SPF_IPS=$(doctor_server_ips)
	if doctor_spf_allows "${records//redirect=/redirect:}" "$domain" 3; then
		dr_ok "The SPF record allows this server." "$records"
		return
	fi
	dr_warn "The SPF record of $domain does not allow this server ($(doctor_main_ip)), so mail sent from here may be rejected. Add ip4:$(doctor_main_ip) or a to the record." "$records"
}
