#!/bin/bash
# Node.js apps (E14): remove the pm2 daemon and the app records of a user
# that is deleted.

"$ARCPANEL/bin/v-delete-user-node-apps" "$1"
