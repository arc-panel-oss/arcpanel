#!/bin/bash
set -euo pipefail
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php vendor/bin/pint --dirty
/usr/local/arcpanel/php/bin/php vendor/bin/pest tests/Feature/ApiV2BackupsTest.php tests/Unit/BackupResourceTest.php tests/Feature/BackupsTest.php
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --check
