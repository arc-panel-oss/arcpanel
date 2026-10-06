#!/bin/bash
# Port registry (E12): give a new user the pool of its package PORTS.
# v-add-reseller-user sets ARC_PORTS_DEFER and allocates the pool itself
# once the owner is known, so the ports come from the reseller's pool.

if [ -n "$ARC_PORTS_DEFER" ]; then
	exit 0
fi
"$ARCPANEL/bin/v-add-user-port-pool" "$1"
