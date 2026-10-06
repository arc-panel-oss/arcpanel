#!/bin/bash

# Function Description
# Manual upgrade script from Nginx + Apache2 + PHP-FPM to Nginx + PHP-FPM

#----------------------------------------------------------#
#                    Variable&Function                     #
#----------------------------------------------------------#

# Includes
# shellcheck source=/etc/arcpanel/arcpanel.conf
source /etc/arcpanel/arcpanel.conf
# shellcheck source=/usr/local/arcpanel/func/main.sh
source $ARCPANEL/func/main.sh
# shellcheck source=/usr/local/arcpanel/conf/arcpanel.conf
source $ARCPANEL/conf/arcpanel.conf

#----------------------------------------------------------#
#                    Verifications                         #
#----------------------------------------------------------#

if [ "$WEB_BACKEND" != "php-fpm" ]; then
	check_result $E_NOTEXISTS "PHP-FPM is not enabled" > /dev/null
	exit 1
fi

if [ "$WEB_SYSTEM" != "apache2" ]; then
	check_result $E_NOTEXISTS "Apache2 is not enabled" > /dev/null
	exit 1
fi

#----------------------------------------------------------#
#                       Action                             #
#----------------------------------------------------------#

# Remove apache2 from config
sed -i "/^WEB_PORT/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
sed -i "/^WEB_SSL/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
sed -i "/^WEB_SSL_PORT/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
sed -i "/^WEB_RGROUPS/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
sed -i "/^WEB_SYSTEM/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf

# Remove nginx (proxy) from config
sed -i "/^PROXY_PORT/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
sed -i "/^PROXY_SSL_PORT/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
sed -i "/^PROXY_SYSTEM/d" $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf

# Add Nginx settings to config
echo "WEB_PORT='80'" >> $ARCPANEL/conf/arcpanel.conf
echo "WEB_SSL='openssl'" >> $ARCPANEL/conf/arcpanel.conf
echo "WEB_SSL_PORT='443'" >> $ARCPANEL/conf/arcpanel.conf
echo "WEB_SYSTEM='nginx'" >> $ARCPANEL/conf/arcpanel.conf

# Add Nginx settings to config
echo "WEB_PORT='80'" >> $ARCPANEL/conf/defaults/arcpanel.conf
echo "WEB_SSL='openssl'" >> $ARCPANEL/conf/defaults/arcpanel.conf
echo "WEB_SSL_PORT='443'" >> $ARCPANEL/conf/defaults/arcpanel.conf
echo "WEB_SYSTEM='nginx'" >> $ARCPANEL/conf/defaults/arcpanel.conf

rm $ARCPANEL/conf/defaults/arcpanel.conf
cp $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf

# Rebuild web config

for user in $($BIN/v-list-users plain | cut -f1); do
	echo $user
	for domain in $($BIN/v-list-web-domains $user plain | cut -f1); do
		$BIN/v-change-web-domain-tpl $user $domain 'default'
		$BIN/v-rebuild-web-domain $user $domain no
	done
done

systemctl restart nginx
