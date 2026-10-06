#!/bin/bash
# wordpress-secure (E6 batch 2): the wp-login.php rate limit needs the zone of
# /etc/nginx/conf.d/arcpanel-wp.conf. Without that file the limit_req line is
# dropped from the rendered configs, so that nginx -t keeps passing.
# Arguments from add_web_config: USER DOMAIN IP HOMEDIR DOCROOT.

if [ ! -f /etc/nginx/conf.d/arcpanel-wp.conf ]; then
	for conf in "${4:-/home}/$1/conf/web/$2/nginx.conf" "${4:-/home}/$1/conf/web/$2/nginx.ssl.conf"; do
		if [ -f "$conf" ]; then
			sed -i '/limit_req zone=arcpanel_wp_login/d' "$conf"
		fi
	done
fi
exit 0
