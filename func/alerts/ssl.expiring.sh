#!/bin/bash
# E16 evaluator of ssl.expiring (spec §4): the days until the certificates
# of the web and mail domains with SSL of the scope's users expire, and for
# the server scope the panel certificate. Prints web:DOMAIN, mail:DOMAIN or
# panel:certificate DAYS LEVEL; lower is worse.

# Lines OBJECT FILE of the certificates of the scope
alerts_ssl_certs() {
	local user dir line domain
	if [ -n "${ALERTS_FIXTURES-}" ]; then
		cat "$ALERTS_FIXTURES/ssl-certs.$AE_SCOPE" 2> /dev/null
		return 0
	fi
	if [ "$AE_SCOPE" = 'system' ] && [ -f "$ARCPANEL/ssl/certificate.crt" ]; then
		echo "panel:certificate $ARCPANEL/ssl/certificate.crt"
	fi
	while IFS= read -r user; do
		dir="$ARCPANEL/data/users/$user"
		while IFS= read -r line; do
			[[ "$line" == *"SSL='yes'"* ]] || continue
			[[ "$line" == *"SUSPENDED='yes'"* ]] && continue
			domain=$(grep -oE "^DOMAIN='[^']*'" <<< "$line" | cut -d"'" -f2)
			[ -n "$domain" ] && [ -f "$dir/ssl/$domain.crt" ] && echo "web:$domain $dir/ssl/$domain.crt"
		done < <(cat "$dir/web.conf" 2> /dev/null)
		while IFS= read -r line; do
			[[ "$line" == *"SSL='yes'"* ]] || continue
			[[ "$line" == *"SUSPENDED='yes'"* ]] && continue
			domain=$(grep -oE "^DOMAIN='[^']*'" <<< "$line" | cut -d"'" -f2)
			[ -n "$domain" ] && [ -f "$dir/ssl/mail.$domain.crt" ] && echo "mail:$domain $dir/ssl/mail.$domain.crt"
		done < <(cat "$dir/mail.conf" 2> /dev/null)
	done < <(alerts_scope_users "$AE_SCOPE")
	return 0
}

# shellcheck disable=SC2154
alert_eval_ssl_expiring() {
	local object file end now days level
	now=$(alerts_now)
	while read -r object file; do
		[ -n "$object" ] && [ -f "$file" ] || continue
		end=$(openssl x509 -enddate -noout -in "$file" 2> /dev/null | cut -d= -f2)
		[ -n "$end" ] || continue
		end=$(date -d "$end" +%s 2> /dev/null) || continue
		days=$(((end - now) / 86400))
		[ $((end - now)) -lt 0 ] && [ "$days" -eq 0 ] && days=-1
		level=$(alert_level "$days" "$AE_WARNING" "$AE_CRITICAL" below)
		echo "$object $days $level expires $(date -d "@$end" +'%F %T')"
	done < <(alerts_ssl_certs)
	return 0
}
