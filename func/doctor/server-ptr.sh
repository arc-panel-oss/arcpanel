#!/bin/bash
# E17 check server.ptr (spec §4.1): the PTR record of the main address (the
# NAT address when set) names the hostname. When the resolver does not
# answer, this check warns that the DNS checks were skipped (spec §7).
# shellcheck disable=SC2034
DOCTOR_ID=server.ptr
DOCTOR_SCOPE=server
DOCTOR_TITLE='The reverse DNS of the server names its hostname'
DOCTOR_DOCS='/docs/guides/doctor#server-ptr'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local ip host ptr back
	host=$(doctor_hostname | tr 'A-Z' 'a-z')
	if ! ip=$(doctor_main_ip) || [ -z "$ip" ]; then
		dr_skip 'The server has no address in the panel.'
		return
	fi
	if doctor_is_ipv4 "$ip" && doctor_ip4_private "$ip"; then
		dr_skip "The main address $ip is private; set its NAT address to check the reverse DNS."
		return
	fi
	if ! ptr=$(doctor_dig PTR "$ip"); then
		dr_warn "$(doctor_resolver_text | sed 's/^t/T/') did not answer, so the DNS checks of the server and its domains were skipped. Allow outgoing DNS or choose another resolver (DOCTOR_RESOLVER)." "$ip"
		return
	fi
	ptr=$(head -n1 <<< "$ptr")
	if [ -z "$ptr" ]; then
		dr_warn "$ip has no PTR record, so other mail servers may reject mail from it. Ask the provider of the address to set it to $host." "$ip"
		return
	fi
	if [ "$ptr" != "$host" ]; then
		dr_warn "The PTR record of $ip is $ptr, not the hostname $host. Ask the provider of the address to change it." "$ip → $ptr"
		return
	fi
	if back=$(doctor_dig A "$ptr") && ! grep -qxF "$ip" <<< "$back"; then
		dr_warn "The PTR record of $ip is $ptr, but $ptr does not resolve back to $ip." "$ip → $ptr"
		return
	fi
	dr_ok "The PTR record of $ip is $ptr." "$ip → $ptr"
}
