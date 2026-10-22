#!/bin/bash
# Upload from URL (E3): cancel the downloads of a user before the home goes.

"$ARCPANEL/bin/v-delete-fs-fetch" "$1" active
