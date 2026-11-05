<?php

namespace App\Services\Jobs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The index of the job files (E15 spec §6.1, D4): the table `jobs_log` of
 * the panel's SQLite database, a projection of `v-list-jobs` that holds
 * nothing the files lack. `sync()` upserts the jobs updated since the newest
 * row minus two minutes; `sync(full: true)` empties the table and builds it
 * again. A missing or unreadable table is rebuilt.
 */
final class JobIndex
{
    /** Columns of jobs_log, from the keys of a job record. */
    private const COLUMNS = [
        'id' => 'ID', 'type' => 'TYPE', 'user' => 'USER', 'owner' => 'OWNER', 'actor' => 'ACTOR',
        'object' => 'OBJECT', 'args' => 'ARGS', 'status' => 'STATUS', 'error' => 'ERROR', 'attempts' => 'ATTEMPTS',
        'retry_of' => 'RETRY_OF', 'resource' => 'RESOURCE', 'queued_at' => 'QUEUED', 'started_at' => 'START',
        'finished_at' => 'END', 'updated_at' => 'UPDATED',
    ];

    /** The migration that creates jobs_log. */
    private const MIGRATION = '2026_11_06_000001_create_jobs_log_table';

    /** Seconds a list may use the index without a new sync. */
    private const FRESH = 15;

    public function __construct(private readonly JobStore $store) {}

    /**
     * Bring the index up to date; returns the number of jobs written.
     *
     * @throws JobIndexUnavailable when the database cannot be written
     */
    public function sync(bool $full = false): int
    {
        try {
            if ($full || ! $this->ready()) {
                $this->rebuild();

                return $this->write($this->store->since(null), true);
            }
            $newest = DB::table('jobs_log')->max('updated_at');
            $since = is_string($newest) && $newest !== '' ? date('Y-m-d H:i:s', strtotime($newest) - 120) : null;

            return $this->write($this->store->since($since), $since === null);
        } catch (JobStoreException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new JobIndexUnavailable('The job index could not be written.', 0, $e);
        }
    }

    /** Sync unless a sync ran in the last few seconds (lists). */
    public function refresh(): void
    {
        if (Cache::get('jobs-index.synced') !== null && $this->ready()) {
            return;
        }
        $this->sync();
        Cache::put('jobs-index.synced', time(), self::FRESH);
    }

    /** Forget the last sync time: the next list syncs (after a cancel or a retry). */
    public function touch(): void
    {
        Cache::forget('jobs-index.synced');
    }

    /** @throws JobIndexUnavailable */
    public function query(): Builder
    {
        if (! $this->ready()) {
            $this->sync(true);
        }

        return DB::table('jobs_log');
    }

    public function ready(): bool
    {
        try {
            return Schema::hasTable('jobs_log');
        } catch (Throwable) {
            return false;
        }
    }

    private function rebuild(): void
    {
        // A table dropped or lost while its migration is still recorded:
        // forget the migration so that migrate creates the table again
        if (! Schema::hasTable('jobs_log') && Schema::hasTable('migrations')) {
            DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        }
        Artisan::call('migrate', ['--force' => true]);
        if (Schema::hasTable('jobs_log')) {
            DB::table('jobs_log')->delete();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobs
     */
    private function write(array $jobs, bool $all): int
    {
        $rows = [];
        foreach ($jobs as $job) {
            $row = self::row($job);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        DB::transaction(function () use ($rows, $all) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('jobs_log')->upsert($chunk, ['id'], array_keys($chunk[0]));
            }
            // A full listing is the whole truth: rows of deleted jobs go
            if ($all) {
                $ids = array_column($rows, 'id');
                DB::table('jobs_log')->whereNotIn('id', $ids === [] ? [''] : $ids)->delete();
            }
        });

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>|null
     */
    public static function row(array $job): ?array
    {
        $id = (string) ($job['ID'] ?? '');
        if (! JobStore::validId($id)) {
            return null;
        }
        $row = [];
        foreach (self::COLUMNS as $column => $key) {
            $row[$column] = is_scalar($job[$key] ?? null) ? (string) $job[$key] : '';
        }
        $exit = (string) ($job['EXIT'] ?? '');
        $row['exit_code'] = ctype_digit($exit) ? min(255, (int) $exit) : null;
        $row['attempts'] = ctype_digit($row['attempts']) ? (int) $row['attempts'] : 0;
        foreach (['error', 'retry_of', 'resource', 'started_at', 'finished_at'] as $nullable) {
            if ($row[$nullable] === '') {
                $row[$nullable] = null;
            }
        }
        if ($row['queued_at'] === '') {
            $row['queued_at'] = $row['started_at'] ?? $row['updated_at'];
        }

        return $row;
    }
}
