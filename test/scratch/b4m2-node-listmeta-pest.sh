#!/usr/bin/env bash
set -euo pipefail
cd /usr/local/arcpanel/web-app
PHP=/usr/local/arcpanel/php/bin/php
$PHP vendor/bin/pest tests/Unit/ApiV2Test.php --filter="documents pagination"
$PHP vendor/bin/pest tests/Feature/ApiV2NodeTest.php tests/Feature/ApiV2NodeAppsTest.php
