<?php

use Illuminate\Support\Facades\Schedule;

// Artisan commands live in app/Console/Commands and are discovered automatically.

// The scheduler runs every minute from the arcpanelweb crontab
// (v-add-sys-panel-files, E3 spec §6.5).
Schedule::command('files:gc')->everyFifteenMinutes()->withoutOverlapping();
