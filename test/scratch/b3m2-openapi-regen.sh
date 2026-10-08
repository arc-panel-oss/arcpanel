#!/usr/bin/env bash
set -euo pipefail
ROOT=/mnt/d/project/arcpanel/arcpanel
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --path="${ROOT}/docs/api/v2/openapi.json"
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --path="${ROOT}/docs/api/v2/openapi.json" --check
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Unit/ApiV2TaskMapperTest.php tests/Feature/ApiV2TasksTest.php
