<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Doctor result (`v-list-sys-doctor`, E17 spec §3.2): the server checks
 * or the web and mail checks of a domain, with their score.
 *
 * @property array{checked_at: ?string, score: ?int, checks: list<array<string, mixed>>} $resource
 */
final class DoctorResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            /** @var string|null when the checks ran, server time; null before the first run */
            'checked_at' => $result['checked_at'] !== null ? str_replace(' ', 'T', $result['checked_at']) : null,
            /** @var int|null (ok + 0.5 × warn) / checks not skipped × 100; null when every check skipped */
            'score' => $result['score'],
            /** @var list<array{id: string, status: string, title: string, message: string, value: string, docs: string, link: string, fix: array{label: string, command: string}|null}> status is `ok`, `warn`, `fail` or `skip`; `fix` names the command a fix runs */
            'checks' => array_map(fn (array $check) => [
                'id' => $check['id'],
                'status' => $check['status'],
                'title' => $check['title'],
                'message' => $check['message'],
                'value' => $check['value'],
                'docs' => $check['docs'],
                'link' => $check['link'],
                'fix' => $check['fix'],
            ], $result['checks']),
        ];
    }
}
