#!/usr/bin/env bash
set -euo pipefail
cd /mnt/d/project/arcpanel/arcpanel
bash test/dev-sync.sh --no-restart
cd web-app
PHP=/usr/local/arcpanel/php/bin/php
php vendor/bin/pint app/Console/Commands/ApiDocs.php app/Http/Controllers/Api/V2/NodeAppController.php tests/Unit/ApiV2Test.php tests/Unit/NodeAppResourceTest.php
$PHP artisan arcpanel:api-docs
$PHP artisan arcpanel:api-docs --check
$PHP vendor/bin/pest tests/Unit/ApiV2Test.php --filter="documents pagination"
$PHP vendor/bin/pest tests/Unit/NodeAppResourceTest.php tests/Unit/NodeAppActionsTest.php
$PHP vendor/bin/pest tests/Feature/ApiV2NodeAppsTest.php tests/Feature/NodeAppsTest.php
