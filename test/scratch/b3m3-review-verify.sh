#!/usr/bin/env bash
set -eu
cd /usr/local/arcpanel/web-app
php vendor/bin/pint \
  app/Http/Controllers/Api/V2/BackupController.php \
  app/Http/Controllers/Api/V2/TaskController.php \
  app/Console/Commands/ApiDocs.php \
  tests/Feature/ApiV2BackupsTest.php \
  tests/Unit/ApiV2Test.php
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs
/usr/local/arcpanel/php/bin/php artisan arcpanel:api-docs --check
/usr/local/arcpanel/php/bin/php vendor/bin/pest \
  tests/Feature/ApiV2BackupsTest.php \
  tests/Feature/ApiV2TasksTest.php \
  tests/Unit/ApiV2Test.php
