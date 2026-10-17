#!/bin/bash
# App processes (E42): remove the units, records and ports of the app
# processes of a user that is deleted.

"$ARCPANEL/bin/v-delete-user-app-processes" "$1"
