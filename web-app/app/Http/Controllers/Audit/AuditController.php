<?php

namespace App\Http\Controllers\Audit;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\AuditPolicy;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server › Audit log (E23 spec §7.1): the events of the server with
 * filters, 50 a page, a detail drawer, the CSV or JSON export of the same
 * filters and, for administrators, the check of the hash chain. A reseller
 * reads the events of its tree only (OWNER).
 */
final class AuditController extends Controller
{
    public const PAGE_SIZE = 50;

    /** Days shown without a range: today and the six before. */
    public const DEFAULT_DAYS = 7;

    public function __construct(private readonly AuditLog $audit) {}

    public function index(Request $request, Principal $principal, AuditPolicy $policy): View
    {
        return $this->page($request, $principal, $policy);
    }

    /** The chain of the shown range, checked by v-check-sys-audit. */
    public function verify(Request $request, Principal $principal, AuditPolicy $policy): View
    {
        abort_unless($policy->verify($principal), 403);
        $filters = $this->filters($request);

        return $this->page($request, $principal, $policy, $this->audit->verify($filters['from'], $filters['to']));
    }

    public function export(Request $request, Principal $principal, AuditPolicy $policy): StreamedResponse
    {
        abort_unless($policy->view($principal), 403);
        $format = $request->validate(['format' => ['required', 'in:csv,json']])['format'];
        $filters = $this->filters($request);
        $body = $this->audit->export($filters['from'], $filters['to'], $filters, $policy->owner($principal), $format);
        $name = sprintf('audit-%s-%s.%s', $filters['from'], $filters['to'], $format);

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $name, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param  array<string, mixed>|null  $verification */
    private function page(Request $request, Principal $principal, AuditPolicy $policy, ?array $verification = null): View
    {
        abort_unless($policy->view($principal), 403);
        $filters = $this->filters($request);
        $page = max(1, (int) $request->query('page', '1'));
        $list = $this->audit->list($filters['from'], $filters['to'], $filters, $policy->owner($principal), self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return view('audit.index', [
            'events' => $list['events'],
            'total' => $list['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PAGE_SIZE)),
            'filters' => $filters,
            'query' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'canVerify' => $policy->verify($principal),
            'verification' => $verification,
        ]);
    }

    /**
     * The validated filters with the default range.
     *
     * @return array{from: string, to: string, actor: ?string, user: ?string, action: ?string, result: ?string, channel: ?string}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate(AuditLog::filterRules());
        $to = $data['to'] ?? gmdate('Y-m-d');
        $from = $data['from'] ?? gmdate('Y-m-d', strtotime($to.' -'.(self::DEFAULT_DAYS - 1).' days'));
        if ($from > $to) {
            $from = $to;
        }

        return [
            'from' => $from,
            'to' => $to,
            'actor' => $data['actor'] ?? null,
            'user' => $data['user'] ?? null,
            'action' => $data['action'] ?? null,
            'result' => $data['result'] ?? null,
            'channel' => $data['channel'] ?? null,
        ];
    }
}
