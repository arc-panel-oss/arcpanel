#!/bin/bash
# Port registry (E12): close the firewall rules of a user that is being
# deleted and give its ports back. Runs before the deletion because the
# owner is read from tenancy.conf, which goes with the user directory.

"$ARCPANEL/bin/v-delete-user-ports" "$1"
