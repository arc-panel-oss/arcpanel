#!/usr/bin/env bash
set -euo pipefail
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Feature/ApiV2DockerAppsTest.php tests/Feature/DockerAppsTest.php tests/Unit/DockerAppActionsTest.php tests/Unit/DockerAppResourceTest.php tests/Unit/ApiV2Test.php --filter=pagination
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --check
