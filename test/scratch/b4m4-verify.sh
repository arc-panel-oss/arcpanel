#!/usr/bin/env bash
set -euo pipefail
cd /usr/local/arcpanel/web-app
vendor/bin/pint --dirty
vendor/bin/pest tests/Feature/ApiV2DockerEngineTest.php tests/Feature/DockerAppPagesTest.php --filter="engine|DockerEngine"
vendor/bin/pest tests/Feature/DockerAppsTest.php --filter="install the engine"
php artisan arcpanel:api-docs --check
