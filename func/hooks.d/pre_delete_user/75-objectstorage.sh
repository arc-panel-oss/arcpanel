#!/bin/bash
# Object storage (E44): delete the buckets, S3 keys and object storage user
# of a user that is deleted.

"$ARCPANEL/bin/v-delete-user-object-storage" "$1"
