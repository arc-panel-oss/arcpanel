<?php

namespace App\Services\Audit;

use App\Arc\ArcCommand;
use App\Arc\Options;

/**
 * Reads the audit log of the server through `v-list-sys-audit` and
 * verifies it through `v-check-sys-audit` (E23 spec §6, §7). The filters
 * are those of the command; a reseller's reads always carry OWNER.
 */
final class AuditLog
{
    /** Events of one export (§7.1). */
    public const EXPORT_LIMIT = 100000;

    public const RESULTS = ['ok', 'denied', 'invalid', 'failed'];

    public const CHANNELS = ['web', 'api_v2', 'api_v1', 'system'];

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * Events between two UTC days, newest first.
     *
     * @param  array{actor?: ?string, user?: ?string, action?: ?string, result?: ?string, channel?: ?string}  $filters
     * @return array{total: int, events: list<array<string, mixed>>, size: int}
     */
    public function list(string $from, string $to, array $filters, ?string $owner, int $limit, int $offset): array
    {
        // FORMAT is not the last argument, so not json()
        $data = json_decode($this->arc->run('v-list-sys-audit', [$from, $to, self::filterWords($filters, $owner), 'json', (string) $limit, (string) $offset])->stdout, true);
        $data = is_array($data) ? $data : [];

        return [
            'total' => (int) ($data['TOTAL'] ?? 0),
            'events' => array_values(array_filter((array) ($data['EVENTS'] ?? []), 'is_array')),
            'size' => (int) ($data['SIZE'] ?? 0),
        ];
    }

    /**
     * The export of the same filters as CSV or JSON, at most EXPORT_LIMIT events.
     *
     * @param  array{actor?: ?string, user?: ?string, action?: ?string, result?: ?string, channel?: ?string}  $filters
     */
    public function export(string $from, string $to, array $filters, ?string $owner, string $format): string
    {
        return $this->arc->run('v-list-sys-audit', [$from, $to, self::filterWords($filters, $owner), $format, (string) self::EXPORT_LIMIT, '0'])->stdout;
    }

    /**
     * The chain between two days: ok, count and, for a break, day, line and reason.
     *
     * @return array{ok: bool, from: string, to: string, count: int, day: ?string, line: ?int, reason: ?string}
     */
    public function verify(string $from, string $to): array
    {
        $result = $this->arc->run('v-check-sys-audit', [$from, $to, 'json'], Options::allowFailure());
        $data = json_decode($result->stdout, true);
        if (! is_array($data) || ! isset($data['OK'])) {
            // Not an answer of the check: a command error
            $this->arc->run('v-check-sys-audit', [$from, $to, 'json']);
        }

        return [
            'ok' => ($data['OK'] ?? false) === true,
            'from' => (string) ($data['FROM'] ?? $from),
            'to' => (string) ($data['TO'] ?? $to),
            'count' => (int) ($data['COUNT'] ?? 0),
            'day' => isset($data['DAY']) ? (string) $data['DAY'] : null,
            'line' => isset($data['LINE']) ? (int) $data['LINE'] : null,
            'reason' => isset($data['REASON']) ? (string) $data['REASON'] : null,
        ];
    }

    /** Bytes of data/audit/ (the Security tab of the server settings). */
    public function size(): int
    {
        $today = gmdate('Y-m-d');

        return $this->list($today, $today, [], null, 1, 0)['size'];
    }

    /**
     * The FILTERS argument: KEY=value words of validated values.
     *
     * @param  array{actor?: ?string, user?: ?string, action?: ?string, result?: ?string, channel?: ?string}  $filters
     */
    public static function filterWords(array $filters, ?string $owner): string
    {
        $words = [];
        foreach (['actor' => 'ACTOR', 'user' => 'USER', 'action' => 'ACTION', 'result' => 'RESULT', 'channel' => 'CHANNEL'] as $key => $word) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $words[] = $word.'='.$value;
            }
        }
        if ($owner !== null) {
            $words[] = 'OWNER='.$owner;
        }

        return implode(' ', $words);
    }

    /**
     * Validation rules of the filters, shared by the page and REST API v2.
     *
     * @return array<string, list<string>>
     */
    public static function filterRules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'actor' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^\S+$/'],
            'user' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            'action' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,127}$/'],
            'result' => ['sometimes', 'nullable', 'in:'.implode(',', self::RESULTS)],
            'channel' => ['sometimes', 'nullable', 'in:'.implode(',', self::CHANNELS)],
        ];
    }
}
