#!/bin/bash

# ArcPanel 2.0.0 upgrade steps (E8): the panel app serves the root URL and the
# web/ directory of the 1.x interface is gone. Code that commands still use
# ships in lib/; runtime data moves to data/. Every step can run again.
# docs/features/specs/2026-10-06-004-e8-legacy-retirement-design.md §8

# Runtime data that lived under web/
echo "[ * ] Moving RRD data and White Label files out of web/..."
mkdir -p "$HESTIA/data/rrd" "$HESTIA/data/white-label"
if [ -d "$HESTIA/web/rrd" ]; then
	cp -a "$HESTIA/web/rrd/." "$HESTIA/data/rrd/"
	rm -rf "$HESTIA/web/rrd"
fi
if [ -d "$HESTIA/web/images/custom" ]; then
	cp -a "$HESTIA/web/images/custom/." "$HESTIA/data/white-label/"
	rm -rf "$HESTIA/web/images/custom"
fi

# The API script keeps the state v-change-sys-api gave it
if [ -f "$HESTIA/web/api/index.php" ] && [ -f "$HESTIA/lib/api/index.php" ] \
	&& grep -q '^die("Error: Disabled");' "$HESTIA/web/api/index.php"; then
	sed -i 's|^//die("Error: Disabled");|die("Error: Disabled");|' "$HESTIA/lib/api/index.php"
fi

# Themes of the panel app replace the CSS themes (spec D11)
arcpanel_e8_theme() {
	case "$1" in
		nocturne | light | auto) echo "$1" ;;
		default | vestia | flat) echo "light" ;;
		*) echo "nocturne" ;;
	esac
}
echo "[ * ] Mapping themes to the panel themes..."
e8_theme="$(grep -o "^THEME='[^']*'" "$HESTIA/conf/hestia.conf" | cut -d"'" -f2)"
if [ "$(arcpanel_e8_theme "$e8_theme")" != "$e8_theme" ]; then
	"$HESTIA/bin/v-change-sys-config-value" THEME "$(arcpanel_e8_theme "$e8_theme")"
fi
for e8_conf in "$HESTIA"/data/users/*/user.conf; do
	[ -f "$e8_conf" ] || continue
	e8_user="$(basename "$(dirname "$e8_conf")")"
	e8_theme="$(grep -o "^THEME='[^']*'" "$e8_conf" | cut -d"'" -f2)"
	if [ -n "$e8_theme" ] && [ "$(arcpanel_e8_theme "$e8_theme")" != "$e8_theme" ]; then
		"$HESTIA/bin/v-change-user-theme" "$e8_user" "$(arcpanel_e8_theme "$e8_theme")"
	fi
done
unset e8_theme e8_conf e8_user

# The switches of 1.x (spec D7)
sed -i "/^ARCPANEL_APP_LOGIN=/d; /^ARCPANEL_APP_PAGES=/d" "$HESTIA/conf/hestia.conf"

# The web/ directory of 1.x, by an explicit list: anything else an
# administrator put there stays and is reported.
if [ -d "$HESTIA/web" ]; then
	echo "[ * ] Removing the 1.x web interface..."
	for e8_entry in add api bulk copy css delete download edit error favicon.ico fm generate \
		images inc index.php js list locale login logout move reset reset2fa restart robots.txt \
		rrd schedule search src start stop suspend templates unsuspend update webfonts; do
		rm -rf "${HESTIA:?}/web/$e8_entry"
	done
	unset e8_entry
	if rmdir "$HESTIA/web" 2> /dev/null; then
		echo "[ * ] $HESTIA/web removed"
	else
		echo "[ ! ] Kept $HESTIA/web, it still holds: $(ls -A "$HESTIA/web" | tr '\n' ' ')"
	fi
fi

# Default logos from lib/images, custom ones from data/white-label
"$HESTIA/bin/v-update-white-label-logo"

# Panel app: allow-list, translations from lib/locale, framework caches
echo "[ * ] Configuring the panel app..."
"$HESTIA/bin/v-add-sys-arcpanel"

# nginx serves web-app/public, lib/ and data/ from the nginx.conf of the
# arcpanel-nginx package unpacked in the same upgrade
if systemctl is-active --quiet arcpanel 2> /dev/null; then
	echo "[ * ] Restarting the panel..."
	systemctl restart arcpanel
fi
