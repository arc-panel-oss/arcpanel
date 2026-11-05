<?php

namespace App\Services\Alerts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The alert history of the panel (E16 spec §7): the table `alerts_history`
 * of the panel database, a projection of `v-list-alert-history all` as E15
 * projects the jobs (E15 spec §6.1). One row per alert id: a fired alert is
 * inserted `firing`, raised and reminded update it, resolved and closed end
 * it; an event is one row that is `resolved` when it is written. Every
 * delivery is kept in `channels`. `sync()` reads the lines since the newest
 * row minus two minutes, so it is idempotent; `sync(full: true)` empties the
 * table and reads the whole history.
 */
final class AlertIndex
{
    private const MIGRATION = '2026_11_06_000002_create_alerts_history_table';

    /** Seconds a page may use the index without a new sync. */
    private const FRESH = 15;

    public function __construct(private readonly AlertStore $store) {}

    /**
     * Bring the index up to date; returns the number of history lines read.
     *
     * @throws AlertIndexUnavailable when the database cannot be written
     */
    public function sync(bool $full = false): int
    {
        try {
            if ($full || ! $this->ready()) {
                $this->rebuild();
                $since = null;
            } else {
                $newest = DB::table('alerts_history')->max('updated_at');
                $since = is_string($newest) && $newest !== '' ? date('Y-m-d H:i:s', (int) strtotime($newest) - 120) : null;
            }
            $lines = $this->store->history('all', $since);
            DB::transaction(function () use ($lines) {
                foreach ($lines as $line) {
                    $this->apply($line);
                }
            });

            return count($lines);
        } catch (Throwable $e) {
            throw new AlertIndexUnavailable('The alert history could not be written.', 0, $e);
        }
    }

    /** Sync unless a sync ran in the last few seconds (the History tab). */
    public function refresh(): void
    {
        if (Cache::get('alerts-index.synced') !== null && $this->ready()) {
            return;
        }
        $this->sync();
        Cache::put('alerts-index.synced', time(), self::FRESH);
    }

    /** @throws AlertIndexUnavailable */
    public function query(): Builder
    {
        if (! $this->ready()) {
            $this->sync(true);
        }

        return DB::table('alerts_history');
    }

    public function ready(): bool
    {
        try {
            return Schema::hasTable('alerts_history');
        } catch (Throwable) {
            return false;
        }
    }

    private function rebuild(): void
    {
        if (! Schema::hasTable('alerts_history') && Schema::hasTable('migrations')) {
            DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        }
        Artisan::call('migrate', ['--force' => true]);
        if (Schema::hasTable('alerts_history')) {
            DB::table('alerts_history')->delete();
        }
    }

    /**
     * Apply one history line (spec §3.3).
     *
     * @param  array<string, mixed>  $line
     */
    public function apply(array $line): void
    {
        $id = (string) ($line['id'] ?? '');
        $event = (string) ($line['event'] ?? '');
        if (preg_match('/^\d{14}$/', $id) !== 1 || $event === 'test') {
            return;
        }
        $time = (string) ($line['time'] ?? '');
        $row = DB::table('alerts_history')->where('id', (int) $id)->first();
        $deliveries = self::deliveries($line, $time, $event);

        if ($row === null) {
            if ($event === 'delivery') {
                return;
            }
            $scope = (string) ($line['scope'] ?? 'system');
            $user = $scope === 'system' ? null : $scope;
            $firing = in_array($event, ['fired', 'raised', 'reminded'], true);
            if ($firing) {
                // An open row of the same alert key left behind ends here
                DB::table('alerts_history')->where('state', 'firing')->where('rule', (string) $line['rule'])
                    ->where('scope', $user === null ? 'system' : 'user')->where('user', $user)
                    ->where('object', (string) $line['object'])
                    ->update(['state' => 'resolved', 'resolved_at' => $time, 'updated_at' => $time]);
            }
            DB::table('alerts_history')->insert([
                'id' => (int) $id,
                'rule' => (string) ($line['rule'] ?? ''),
                'scope' => $user === null ? 'system' : 'user',
                'object' => (string) ($line['object'] ?? ''),
                'user' => $user,
                'owner' => ($line['owner'] ?? '') !== '' ? (string) $line['owner'] : null,
                'severity' => self::severity($line['severity'] ?? ''),
                'state' => $firing ? 'firing' : 'resolved',
                'value' => ($line['value'] ?? '') !== '' ? (string) $line['value'] : null,
                'message' => (string) ($line['message'] ?? ''),
                'channels' => json_encode($deliveries),
                'fired_at' => (string) (($line['since'] ?? '') !== '' && $firing ? $line['since'] : $time),
                'resolved_at' => $firing ? null : $time,
                'last_notified_at' => self::sent($deliveries) ? $time : null,
                'notify_count' => max(1, (int) ($line['count'] ?? 1)),
                'created_at' => $time,
                'updated_at' => $time,
            ]);

            return;
        }

        $channels = json_decode((string) $row->channels, true);
        $channels = is_array($channels) ? $channels : [];
        foreach ($deliveries as $delivery) {
            if (! in_array($delivery, $channels, true)) {
                $channels[] = $delivery;
            }
        }
        $update = ['channels' => json_encode($channels)];
        if ($time > (string) $row->updated_at) {
            $update['updated_at'] = $time;
        }
        if (in_array($event, ['raised', 'reminded'], true)) {
            $update['severity'] = self::severity($line['severity'] ?? $row->severity);
            $update['value'] = ($line['value'] ?? '') !== '' ? (string) $line['value'] : $row->value;
            $update['notify_count'] = max((int) $row->notify_count, (int) ($line['count'] ?? 0));
            $update['last_notified_at'] = $time;
            $update['message'] = (string) ($line['message'] ?? $row->message);
        } elseif (in_array($event, ['resolved', 'closed'], true)) {
            $update['state'] = 'resolved';
            $update['resolved_at'] = $time;
            $update['value'] = ($line['value'] ?? '') !== '' ? (string) $line['value'] : $row->value;
        } elseif ($event === 'delivery' && self::sent($deliveries)) {
            $update['last_notified_at'] = $time;
        }
        DB::table('alerts_history')->where('id', (int) $id)->update($update);
    }

    /**
     * The deliveries of a line, each with its time and event.
     *
     * @param  array<string, mixed>  $line
     * @return list<array<string, string>>
     */
    private static function deliveries(array $line, string $time, string $event): array
    {
        $out = [];
        foreach ((array) ($line['deliveries'] ?? []) as $delivery) {
            if (! is_array($delivery)) {
                continue;
            }
            $out[] = [
                'time' => $time,
                'event' => $event,
                'channel' => (string) ($delivery['channel'] ?? ''),
                'name' => (string) ($delivery['name'] ?? ''),
                'type' => (string) ($delivery['type'] ?? ''),
                'result' => (string) ($delivery['result'] ?? ''),
                'error' => (string) ($delivery['error'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param  list<array<string, string>>  $deliveries */
    private static function sent(array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if ($delivery['result'] === 'sent') {
                return true;
            }
        }

        return false;
    }

    private static function severity(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, AlertStore::SEVERITIES, true) ? $value : 'info';
    }
}
