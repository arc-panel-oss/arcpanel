#!/bin/bash
# ArcPanel 2.1.3 upgrade step (E39 W3,
# docs/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md §6, §7).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# The panel certificate is public: the phpMyAdmin single sign-on checks the
# panel with it and runs as arcpanelmail, which must be able to read it. The
# key keeps its mode.
if [ -f "$ARCPANEL/ssl/certificate.crt" ]; then
	chmod 644 "$ARCPANEL/ssl/certificate.crt"
fi

# Keyrings written before 2.1.3 were trusted on first download. Report any
# key in them that is not pinned in func/download.sh; nothing is removed.
for keyring in arcpanel:arcpanel-keyring.gpg nginx:nginx-keyring.gpg sury:sury-keyring.gpg \
	sury:apache2-keyring.gpg mariadb:mariadb-keyring.gpg postgresql:postgresql-keyring.gpg \
	nodesource:nodejs.gpg mysql:mysql-keyring.gpg; do
	name="${keyring%%:*}"
	file="/usr/share/keyrings/${keyring#*:}"
	[ -f "$file" ] || continue
	var="APT_KEY_FPR_${name^^}"
	for fpr in $(gpg --batch --show-keys --with-colons "$file" 2> /dev/null | awk -F: '$1 == "pub" { pub = 1; next } pub && $1 == "fpr" { print $10; pub = 0 }'); do
		if [[ " ${!var} " != *" $fpr "* ]]; then
			echo "[ ! ] $file holds a key that ArcPanel does not expect ($fpr)"
			add_upgrade_message "Warning: $file holds the key $fpr, which is not the known key of the $name repository. Check the repositories in /etc/apt/sources.list.d/."
		fi
	done
done
unset keyring name file var fpr
