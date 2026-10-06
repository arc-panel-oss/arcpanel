#!/bin/bash

if [ ! -x /usr/bin/xgettext ]; then
	echo " **********************************************************"
	echo " * Unable to find xgettext please install gettext package *"
	echo " **********************************************************"
	exit 3
fi

echo "[ * ] Move arcpanel.pot to arcpanel.pot.old"
mv arcpanel.pot arcpanel.pot.old
true > arcpanel.pot

echo "[ * ] Search *.php *.html and *.sh for php based gettext functions"
# __() is the translation helper of the ArcPanel panel app (web-app/)
find ../.. \( -path '*/vendor' -o -path '*/node_modules' \) -prune -o \( -name '*.php' -o -name '*.html' -o -name '*.sh' \) -print | xgettext --output=arcpanel.pot --language=PHP --keyword=__ --join-existing -f -

# Scan the description string for list updates page
while IFS= read -r string; do
	if ! grep -q "\"$string\"" arcpanel.pot; then
		echo -e "\n#: ../../bin/v-list-sys-arcpanel-updates:$(grep -n "$string" ../../bin/v-list-sys-arcpanel-updates | cut -d: -f1)\nmsgid \"$string\"\nmsgstr \"\"" >> arcpanel.pot
	fi
done < <(awk -F'DESCR=' '/data=".+ DESCR=[^"]/ {print $2}' ../../bin/v-list-sys-arcpanel-updates | cut -d\' -f2)

# Scan the description string for list server page
while IFS= read -r string; do
	if ! grep -q "\"$string\"" arcpanel.pot; then
		echo -e "\n#: ../../bin/v-list-sys-services:$(grep -n "$string" ../../bin/v-list-sys-services | cut -d: -f1)\nmsgid \"$string\"\nmsgstr \"\"" >> arcpanel.pot
	fi
done < <(awk -F'SYSTEM=' '/data=".+ SYSTEM=[^"]/ {print $2}' ../../bin/v-list-sys-services | cut -d\' -f2)

# Prevent only date change become a commit
if [ "$(diff arcpanel.pot arcpanel.pot.old | wc -l)" -gt 4 ]; then
	rm arcpanel.pot.old
else
	mv -f arcpanel.pot.old arcpanel.pot
fi
