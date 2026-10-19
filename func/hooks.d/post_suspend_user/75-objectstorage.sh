#!/bin/bash
# Object storage (E44): disable the S3 keys of a suspended user.

"$ARCPANEL/bin/v-suspend-user-object-storage" "$1"
