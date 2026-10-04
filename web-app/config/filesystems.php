<?php

return [

    'default' => 'local',

    // The panel app serves no files from storage: no storage/{path} routes.
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],
    ],

    'links' => [],

];
