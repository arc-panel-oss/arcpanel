#!/bin/bash
# Per-domain PHP settings (E19): write again the pools whose settings the
# new package's maximums cap, then reload their PHP-FPM versions. The old
# package is not known here, so every pool with a capped setting is written.

# shellcheck source=/etc/arcpanel/arcpanel.conf
source /etc/arcpanel/arcpanel.conf
# shellcheck source=/usr/local/arcpanel/func/main.sh
source "$ARCPANEL/func/main.sh"
# shellcheck source=/usr/local/arcpanel/func/php.sh
source "$ARCPANEL/func/php.sh"
# load config file
source_conf "$ARCPANEL/conf/arcpanel.conf"

for version in $(php_settings_rerender "$1" | sort -u); do
	"$BIN/v-restart-web-backend" yes "$version" > /dev/null 2>&1
done
