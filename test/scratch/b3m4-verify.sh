#!/bin/bash
set -eu
cd /usr/local/arcpanel/web-app
/usr/local/arcpanel/php/bin/php vendor/bin/pest \
  tests/Feature/ApiV2BackupsTest.php \
  tests/Feature/BackupsTest.php \
  tests/Unit/SnapshotResourceTest.php \
  tests/Unit/BackupResourceTest.php \
  tests/Unit/ApiV2Test.php
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --check
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs
