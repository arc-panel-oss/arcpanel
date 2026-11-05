#!/bin/bash
# E17 check web.https-redirect (spec §4.2): with SSL on, http:// answers a
# permanent redirect (301 or 308) to https://.
# shellcheck disable=SC2034
DOCTOR_ID=web.https-redirect
DOCTOR_SCOPE=web
DOCTOR_TITLE='HTTP redirects to HTTPS'
DOCTOR_DOCS='/docs/guides/doctor#web-https-redirect'
DOCTOR_FIX_ALLOWED='v-add-web-domain-ssl-force'

doctor_check() {
	local user="$1" domain="$2" line ip port out code location
	line=$(doctor_domain_line "$user" web "$domain")
	if [ "$(doctor_field "$line" SSL)" != 'yes' ]; then
		dr_skip 'SSL is off for the domain.'
		return
	fi
	ip=$(doctor_local_ip "$(doctor_field "$line" IP)")
	port=$(doctor_http_port)
	out=$(doctor_src "http-$domain" curl -s -o /dev/null --max-time 10 -w '%{http_code} %{redirect_url}' \
		--resolve "$domain:$port:$ip" "http://$domain:$port/" 2> /dev/null)
	code=${out%% *}
	location=${out#* }
	if [ -z "$code" ] || [ "$code" = '000' ]; then
		dr_skip "The server did not answer HTTP for $domain."
		return
	fi
	if { [ "$code" = '301' ] || [ "$code" = '308' ]; } && [[ "$location" == https://* ]]; then
		dr_ok "http://$domain redirects to HTTPS ($code)." "$code"
		return
	fi
	if [[ "$location" == https://* ]]; then
		dr_warn "http://$domain redirects to HTTPS with $code, a temporary redirect; search engines expect 301." "$code"
	else
		dr_warn "http://$domain answers $code without redirecting to HTTPS, so visitors can use the site unencrypted." "$code"
	fi
	dr_fix 'Force HTTPS' v-add-web-domain-ssl-force "$user" "$domain"
}
