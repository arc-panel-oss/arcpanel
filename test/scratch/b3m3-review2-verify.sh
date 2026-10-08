#!/usr/bin/env bash
set -euo pipefail
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php vendor/bin/pint tests/Pest.php tests/Feature/BackupsTest.php
echo '=== ApiV2BackupsTest.php ==='
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Feature/ApiV2BackupsTest.php
echo '=== BackupsTest.php ==='
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Feature/BackupsTest.php
