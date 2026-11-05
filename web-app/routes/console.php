<?php

use Illuminate\Support\Facades\Schedule;

// Artisan commands live in app/Console/Commands and are discovered automatically.

// The scheduler runs every minute from the arcpanelweb crontab
// (v-add-sys-panel-files, E3 spec §6.5).
Schedule::command('files:gc')->everyFifteenMinutes()->withoutOverlapping();

// The job index (E15 spec §6.1): the jobs changed since the last run, and
// a full rebuild every night
Schedule::command('arcpanel:jobs-sync')->everyMinute()->withoutOverlapping();
Schedule::command('arcpanel:jobs-sync --full')->dailyAt('03:50')->withoutOverlapping();
