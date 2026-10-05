#!/bin/bash
# Port registry (E12): reopen the public ports of an unsuspended user.

"$HESTIA/bin/v-unsuspend-user-ports" "$1"
