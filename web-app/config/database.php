<?php

/*
| The panel's own database (E15, docs/design/15-database-schema-and-erd.md):
| one SQLite file owned by arcpanelweb, data/arcpanel/arcpanel.sqlite. It
| holds projections that the panel rebuilds from the files of the CLI at any
| time (jobs_log is built from v-list-jobs), so it is never backed up and
| never part of an update snapshot.
*/

return [

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => env('DB_DATABASE', '/usr/local/arcpanel/data/arcpanel/arcpanel.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
