<?php

require '/usr/local/arcpanel/web-app/vendor/autoload.php';
$app = require '/usr/local/arcpanel/web-app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$gen = app(Dedoc\Scramble\Generator::class);
$spec = $gen->generate(Dedoc\Scramble\Scramble::getGeneratorConfig('default'))->spec();
$op = $spec['paths']['/users/{user}/snapshots/{snapshot}/files']['get']['responses'][200]['content']['application/json']['schema'] ?? [];
echo json_encode($op, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
$schemas = array_keys($spec['components']['schemas'] ?? []);
echo "schemas matching Snapshot: ", implode(', ', array_filter($schemas, fn ($s) => str_contains($s, 'Snapshot'))), "\n";
