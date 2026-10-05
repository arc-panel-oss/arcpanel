#!/bin/bash
# Node.js apps (E14): stop the Node.js apps and the pm2 daemon of a suspended user.

"$HESTIA/bin/v-suspend-user-node-apps" "$1"
