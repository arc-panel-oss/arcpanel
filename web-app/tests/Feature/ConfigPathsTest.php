<?php

// Full rename (ADR-0007, spec 2026-10-06-005 §11): the panel app knows the
// ArcPanel paths only.

it('names no hestia path or user in config/arc.php', function () {
    expect(file_get_contents(config_path('arc.php')))->not->toMatch('/hestia/i');
});

it('defaults the install, command and session paths to /usr/local/arcpanel', function () {
    $config = require config_path('arc.php');

    foreach (['root', 'bin_path', 'install_dir', 'sessions_path'] as $key) {
        expect($config[$key])->toStartWith('/usr/local/arcpanel');
    }
});
