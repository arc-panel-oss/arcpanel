<?php

use Illuminate\Support\Facades\Artisan;

// The allow-list of ArcCommand (config/arc-commands.php).

it('lists the v-* scripts of bin/ but never v-get-user-secret (E38 D7)', function () {
    $bin = sys_get_temp_dir().'/arc-allow-list-'.bin2hex(random_bytes(4));
    mkdir($bin);
    foreach (['v-list-user', 'v-add-user-secret', 'v-get-user-secret', 'README'] as $file) {
        file_put_contents($bin.'/'.$file, "#!/bin/bash\n");
    }
    $target = config_path('arc-commands.php');
    $saved = is_file($target) ? file_get_contents($target) : null;

    try {
        expect(Artisan::call('arcpanel:allow-list', ['--bin' => $bin]))->toBe(0);
        $commands = require $target;
        expect($commands)->toBe(['v-add-user-secret', 'v-list-user']);
    } finally {
        $saved === null ? @unlink($target) : file_put_contents($target, $saved);
        array_map('unlink', glob($bin.'/*') ?: []);
        rmdir($bin);
    }
});
