<?php

namespace App\Http\Controllers\Dns;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Concerns\ChecksBulkItems;
use App\Http\Controllers\Controller;
use App\Policies\DnsPolicy;
use App\Services\Dns\DnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Single and bulk actions on DNS zones and records (web/bulk/dns,
 * web/suspend/dns, web/unsuspend/dns, web/delete/dns).
 */
final class DnsActionController extends Controller
{
    use ChecksBulkItems;

    public function __construct(private readonly DnsService $dns) {}

    public function suspend(string $user, string $domain, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $user, $domain, $policy->suspend($principal, $user));
    }

    public function unsuspend(string $user, string $domain, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $user, $domain, $policy->suspend($principal, $user));
    }

    public function destroy(string $user, string $domain, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        return $this->single('delete', $user, $domain, $policy->delete($principal, $user));
    }

    /** POST /app/dns/bulk {action, items[]} with items "user/domain". */
    public function bulk(Request $request, Principal $principal, DnsPolicy $policy, OwnerScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(DnsService::BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'max:300', 'regex:/^[[:alnum:]][-.\w]*\/[^\/\s]{1,253}$/'],
        ]);
        $action = $data['action'];
        $items = $this->bulkItems($data['items'], 2, $scope, fn (string $owner) => $policy->action($action, $principal, $owner));

        return $this->dns->bulk($action, $items)
            ->flash(redirect()->route('dns.index', array_filter(['owner' => $request->query('owner')])));
    }

    public function suspendRecord(string $user, string $domain, string $record, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        return $this->singleRecord('suspend', $user, $domain, $record, $policy->suspend($principal, $user));
    }

    public function unsuspendRecord(string $user, string $domain, string $record, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        return $this->singleRecord('unsuspend', $user, $domain, $record, $policy->suspend($principal, $user));
    }

    public function destroyRecord(string $user, string $domain, string $record, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        return $this->singleRecord('delete', $user, $domain, $record, $policy->delete($principal, $user));
    }

    /**
     * POST /app/dns/{user}/{domain}/records/bulk {action, items[]} with the
     * record ids of the zone; the owner and the zone come from the URL.
     */
    public function bulkRecords(Request $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(DnsService::RECORD_BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'regex:/^\d{1,9}$/'],
        ]);
        $action = $data['action'];
        abort_unless($policy->action($action, $principal, $user), 403);
        $this->dns->find($user, $domain);

        return $this->dns->bulkRecords($action, $user, $domain, array_values(array_unique($data['items'])))
            ->flash(redirect()->route('dns.records.index', ['user' => $user, 'domain' => $domain]));
    }

    private function single(string $action, string $user, string $domain, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $this->dns->find($user, $domain);

        $result = $this->dns->single($action, $user, $domain);
        $back = $action === 'delete'
            ? redirect()->route('dns.index')
            : redirect()->back(fallback: route('dns.index'));

        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        return $back->with('ok', match ($action) {
            'suspend' => sprintf(__('DNS zone %s has been suspended.'), $domain),
            'unsuspend' => sprintf(__('DNS zone %s has been unsuspended.'), $domain),
            default => sprintf(__('DNS zone %s has been deleted.'), $domain),
        });
    }

    private function singleRecord(string $action, string $user, string $domain, string $id, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $record = $this->dns->findRecord($user, $domain, $id);

        $result = $this->dns->singleRecord($action, $user, $domain, $id);
        $back = redirect()->route('dns.records.index', ['user' => $user, 'domain' => $domain]);
        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        $name = $record->record.'.'.$domain;

        return $back->with('ok', match ($action) {
            'suspend' => sprintf(__('Record %s has been suspended.'), $name),
            'unsuspend' => sprintf(__('Record %s has been unsuspended.'), $name),
            default => sprintf(__('Record %s has been deleted.'), $name),
        });
    }
}
