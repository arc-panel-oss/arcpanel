#!/usr/bin/env bash
set -euo pipefail
ROOT=/mnt/d/project/arcpanel/arcpanel
WEB=$ROOT/web-app
PHP=/usr/local/arcpanel/php/bin/php

cd "$WEB"
vendor/bin/pint app/Console/Commands/ApiDocs.php
$PHP artisan arcpanel:api-docs
$PHP artisan arcpanel:api-docs --check
$PHP vendor/bin/pest tests/Feature/ApiV2DockerAppsTest.php
