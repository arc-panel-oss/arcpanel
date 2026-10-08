#!/usr/bin/env bash
set -euo pipefail
bash /mnt/d/project/arcpanel/arcpanel/test/dev-sync.sh
SMOKE=/tmp/arcpanel-api-v2-smoke.sh
sed 's/\r$//' /mnt/d/project/arcpanel/arcpanel/test/arcpanel-api-v2-smoke.sh > "$SMOKE"
chmod +x "$SMOKE"
bash "$SMOKE"
