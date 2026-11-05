<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertIndex;
use App\Services\Alerts\AlertIndexUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Projects the alert history files into alerts_history (E16 spec §7): the
 * lines since the newest row minus two minutes, or with --full the whole
 * history after emptying the table. Runs every minute and nightly (--full)
 * from the scheduler; a missing table is rebuilt.
 */
#[Signature('arcpanel:alerts-sync {--full : Empty the alert history table and build it again}')]
#[Description('Update the alert history of the panel from the alert files')]
final class AlertsSync extends Command
{
    public function handle(AlertIndex $index): int
    {
        try {
            $count = $index->sync((bool) $this->option('full'));
        } catch (AlertIndexUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$count} history lines read");

        return self::SUCCESS;
    }
}
