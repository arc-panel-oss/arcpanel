#!/usr/bin/env bash
cd /usr/local/arcpanel/web-app
PHP=/usr/local/arcpanel/php/bin/php
echo "=== pest ApiV2NodeTest ==="
$PHP vendor/bin/pest tests/Feature/ApiV2NodeTest.php
echo "=== pest NodeTest ==="
$PHP vendor/bin/pest tests/Feature/NodeTest.php
echo "=== api-docs --check ==="
$PHP artisan arcpanel:api-docs --check
