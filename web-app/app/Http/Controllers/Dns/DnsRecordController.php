<?php

namespace App\Http\Controllers\Dns;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\DnsRecordRequest;
use App\Http\Requests\Dns\UpdateDnsRecordRequest;
use App\Policies\DnsPolicy;
use App\Services\Dns\DnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The records of a DNS zone (web/list/dns?domain=), added and edited in a
 * drawer of the list page (web/add/dns?domain=, web/edit/dns?record_id=):
 * ?add=1 opens the empty form, ?edit=ID the form of record ID.
 */
final class DnsRecordController extends Controller
{
    public function __construct(private readonly DnsService $dns) {}

    public function index(Request $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $zone = $this->dns->find($user, $domain);
        $records = $this->dns->records($user, $domain);
        $can = $policy->abilities($principal, $user);

        $editing = null;
        $edit = (string) $request->query('edit', '');
        if ($edit !== '') {
            abort_unless(preg_match('/^\d{1,9}$/', $edit) === 1, 404);
            $editing = $this->dns->findRecord($user, $domain, $edit);
        }

        return view('dns.records', [
            'zone' => $zone,
            'records' => $records,
            'can' => $can,
            'editing' => $can['update'] ? $editing : null,
            'openForm' => $can['update'] && ($editing !== null || $request->boolean('add') || session()->has('record_error') || session()->has('errors')),
            'ips' => $can['update'] ? $this->dns->userIps($user) : [],
            'types' => DnsService::RECORD_TYPES,
            'priorityTypes' => DnsService::PRIORITY_TYPES,
            'canBulk' => $can['update'],
            'bulkActions' => [
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d DNS records?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d DNS records?')],
            ],
        ]);
    }

    public function store(DnsRecordRequest $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $this->dns->find($user, $domain);

        $fields = $request->fields();
        $result = $this->dns->apply([$this->dns->planAddRecord($user, $domain, $fields)]);
        if (! $result->ok()) {
            return back()->withInput()->with('record_error', $result->error);
        }

        return redirect()->route('dns.records.index', ['user' => $user, 'domain' => $domain])
            ->with('ok', sprintf(__('Record %s.%s has been created successfully.'), $fields['record'], $domain));
    }

    public function update(UpdateDnsRecordRequest $request, string $user, string $domain, string $record, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $current = $this->dns->findRecord($user, $domain, $record);

        $newId = $request->newId();
        $result = $this->dns->apply($this->dns->planUpdateRecord($current, $request->fields(), $newId));
        if (! $result->ok()) {
            return back()->withInput()->with('record_error', $result->error);
        }

        // As web/edit/dns, stay on the record, under its new id when it changed.
        $id = $newId !== '' && $result->changed() ? $newId : $record;

        return redirect()->route('dns.records.index', ['user' => $user, 'domain' => $domain, 'edit' => $id])
            ->with('ok', __('Changes have been saved.'));
    }
}
