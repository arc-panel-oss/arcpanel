#!/bin/bash
# ArcPanel 2.3.0 upgrade step (E40 firewall on nftables, with IPv6,
# docs/features/specs/2026-10-19-002-e40-nftables-firewall-design.md §9).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

if [ "$FIREWALL_SYSTEM" = 'iptables' ] || [ "$FIREWALL_SYSTEM" = 'nftables' ]; then
	if [ ! -x /usr/sbin/nft ]; then
		apt-get --quiet --yes install nftables > /dev/null 2>&1
	fi

	# The Fail2Ban action that bans into the nftables sets
	if [ -n "$FIREWALL_EXTENSION" ] && [ -d /etc/fail2ban/action.d ]; then
		cp -f "$ARCPANEL_INSTALL_DIR/fail2ban/action.d/arcpanel.conf" /etc/fail2ban/action.d/arcpanel.conf
	fi

	# Load the nftables table; only when it loads are the iptables rules,
	# ipsets and unit removed. Otherwise the server keeps them and the
	# value iptables.
	if [ "$FIREWALL_SYSTEM" = 'iptables' ]; then
		if fw_output=$("$BIN/v-add-sys-firewall" 2>&1); then
			echo "[ * ] The firewall now runs on nftables, for IPv4 and IPv6."
		else
			fw_output=$(echo "$fw_output" | sed -n 's/^Error: //p' | head -n 1)
			echo "[ ! ] The firewall stays on iptables: $fw_output"
			"$BIN/v-log-action" "system" "Warning" "Firewall" "The firewall could not move to nftables and stays on iptables: $fw_output"
		fi
	fi

	if [ -f "$ARCPANEL/data/firewall/custom.sh" ] && grep -qE '(^|[^[:alnum:]_-])ip6?tables([^[:alnum:]_-]|$)' "$ARCPANEL/data/firewall/custom.sh"; then
		echo "[ ! ] data/firewall/custom.sh calls iptables: its rules cannot open ports the nftables firewall drops. Move them to data/firewall/custom.nft."
		"$BIN/v-log-action" "system" "Warning" "Firewall" "data/firewall/custom.sh calls iptables; move its rules to data/firewall/custom.nft."
	fi

	# What the move may have closed or put at risk: SSH or the panel closed
	# over IPv6, the distribution's nftables.service (spec §16)
	if [ "$("$BIN/v-check-firewall" json 2> /dev/null | jq -r '.ENGINE' 2> /dev/null)" = 'nftables' ]; then
		# shellcheck source=/usr/local/arcpanel/func/firewall.sh
		source "$ARCPANEL/func/firewall.sh"
		fw_mask_distro_unit
		for fw_warning in $("$BIN/v-check-firewall" json 2> /dev/null | jq -r '.WARNINGS[]?' 2> /dev/null); do
			fw_text=$(fw_warning_text "$fw_warning")
			echo "[ ! ] $fw_text"
			"$BIN/v-log-action" "system" "Warning" "Firewall" "$fw_text"
		done
	fi

	if [ -n "$FIREWALL_EXTENSION" ] && systemctl -q is-active fail2ban 2> /dev/null; then
		systemctl restart fail2ban
	fi
fi
