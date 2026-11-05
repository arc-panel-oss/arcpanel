<?php

namespace App\Console\Commands;

use App\Services\Jobs\JobIndex;
use App\Services\Jobs\JobIndexUnavailable;
use App\Services\Jobs\JobStoreException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Projects the job files into jobs_log (E15 spec §6.1): the jobs updated
 * since the newest row minus two minutes, or with --full every job after
 * emptying the table. Runs every minute and nightly (--full) from the
 * scheduler; a missing or unreadable table is rebuilt.
 */
#[Signature('arcpanel:jobs-sync {--full : Empty the index and build it again from every job}')]
#[Description('Update the job index of the panel from the job files')]
final class JobsSync extends Command
{
    public function handle(JobIndex $index): int
    {
        try {
            $count = $index->sync((bool) $this->option('full'));
        } catch (JobStoreException|JobIndexUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$count} jobs written to the index");

        return self::SUCCESS;
    }
}
