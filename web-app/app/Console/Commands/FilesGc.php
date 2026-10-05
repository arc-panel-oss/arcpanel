<?php

namespace App\Console\Commands;

use App\Services\Files\DownloadTickets;
use App\Services\Files\UploadSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Removes upload sessions older than 24 hours and download tickets older
 * than an hour from the file manager's staging tree (E3 spec §6.5). Runs
 * from the scheduler every 15 minutes.
 */
#[Signature('files:gc')]
#[Description('Remove stale uploads and download tickets of the file manager')]
final class FilesGc extends Command
{
    public function handle(UploadSessions $uploads, DownloadTickets $tickets): int
    {
        $sessions = $uploads->gc();
        $downloads = $tickets->gc();
        if ($sessions + $downloads > 0) {
            Log::channel('arc')->info('files gc', ['sessions' => $sessions, 'tickets' => $downloads]);
        }
        $this->info("{$sessions} upload sessions and {$downloads} download tickets removed");

        return self::SUCCESS;
    }
}
