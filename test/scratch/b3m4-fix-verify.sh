#!/usr/bin/env bash
set -euo pipefail
OPENAPI=/mnt/d/project/arcpanel/arcpanel/docs/api/v2/openapi.json
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Feature/ApiV2BackupsTest.php
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --path="$OPENAPI" --check
