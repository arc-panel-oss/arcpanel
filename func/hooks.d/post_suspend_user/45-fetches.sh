#!/bin/bash
# Upload from URL (E3): cancel the downloads of a suspended user.

"$ARCPANEL/bin/v-delete-fs-fetch" "$1" active
