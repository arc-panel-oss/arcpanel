#!/usr/bin/env bash
set -euo pipefail
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Feature/ApiV2DatabasesTest.php
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --check
