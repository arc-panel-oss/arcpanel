#!/bin/bash
set -euo pipefail
ARCPANEL=/usr/local/arcpanel
ROOT=$(grep "^ROOT_USER=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
SECRET=$(mktemp)
trap 'rm -f "$SECRET"' EXIT
KEY=$( "$ARCPANEL/bin/v-add-access-key" "$ROOT" v2-backup scratch json | jq -r '"\(.ACCESS_KEY_ID):\(.SECRET_ACCESS_KEY)"' )
TASK='queue:arcsmoke10861:17914005530369'
curl -sk -H "Authorization: Bearer $KEY" "https://127.0.0.1:8083/api/v2/tasks/$TASK" | jq .
"$ARCPANEL/bin/v-delete-access-key" "${KEY%%:*}" > /dev/null 2>&1 || true
