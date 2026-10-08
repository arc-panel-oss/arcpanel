#!/usr/bin/env bash
set -euo pipefail
ARCPANEL=/usr/local/arcpanel
PHP="${ARCPANEL}/php/bin/php"
WEB="${ARCPANEL}/web-app"
cd "${WEB}"
vendor/bin/pint app/Console/Commands/ApiDocs.php
"${PHP}" artisan arcpanel:api-docs
"${PHP}" artisan arcpanel:api-docs --check
"${PHP}" vendor/bin/pest tests/Feature/ApiV2DockerAppsTest.php
"${PHP}" vendor/bin/pest tests/Unit/ApiV2Test.php --filter=pagination
