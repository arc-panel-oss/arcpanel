#!/bin/bash
# Secret store (E38): create the keys of a new user when the store is set up.

# shellcheck source=/usr/local/arcpanel/func/secret.sh
source "$ARCPANEL/func/secret.sh"

if command -v age > /dev/null 2>&1 && [ -f "$SECRET_MASTER/identity" ]; then
	secret_user_init "$1"
fi
