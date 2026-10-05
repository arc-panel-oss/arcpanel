#!/bin/bash
# Port registry (E12): close the public ports of a suspended user.

"$HESTIA/bin/v-suspend-user-ports" "$1"
