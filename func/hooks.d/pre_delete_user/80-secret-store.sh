#!/bin/bash
# Secret store (E38): remove the secrets and the key of a user that is
# deleted.

# shellcheck source=/usr/local/arcpanel/func/secret.sh
source "$ARCPANEL/func/secret.sh"

secret_user_remove "$1"
