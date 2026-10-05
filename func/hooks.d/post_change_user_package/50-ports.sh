#!/bin/bash
# Port registry (E12): top the pool up to the new package PORTS. A smaller
# PORTS never takes ports away.

"$HESTIA/bin/v-add-user-port-pool" "$1"
