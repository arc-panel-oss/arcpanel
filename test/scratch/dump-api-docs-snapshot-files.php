<?php

require '/usr/local/arcpanel/web-app/vendor/autoload.php';
$app = require '/usr/local/arcpanel/web-app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$gen = app(Dedoc\Scramble\Generator::class);
$spec = $gen->generate(Dedoc\Scramble\Scramble::getGeneratorConfig('default'))->spec();
$cmd = new App\Console\Commands\ApiDocs;
$ref = new ReflectionClass($cmd);
$doc = $ref->getMethod('document');
$doc->setAccessible(true);
$final = $doc->invoke($cmd, $spec);
$op = $final['paths']['/users/{user}/snapshots/{snapshot}/files']['get']['responses']['200']['content']['application/json']['schema'] ?? [];
echo json_encode($op, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
