<?php

namespace App\Http\Controllers\Dns;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\StoreDnsZoneRequest;
use App\Http\Requests\Dns\UpdateDnsZoneRequest;
use App\Policies\Capabilities;
use App\Policies\DnsPolicy;
use App\Services\Dns\DnsActions;
use App\Services\Dns\DnsService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DNS zone list, creation, edit and DNSSEC key (web/list/dns, web/add/dns,
 * web/edit/dns).
 */
final class DnsController extends Controller
{
    /** Lists longer than this are paged on the server. */
    public const PAGE_SIZE = 500;

    public function __construct(
        private readonly DnsService $dns,
        private readonly DnsActions $actions,
    ) {}

    public function index(Request $request, Principal $principal, DnsPolicy $policy, OwnerList $ownerList): View
    {
        abort_unless($policy->viewAny($principal), 403);

        $selection = $ownerList->forList($request->query('owner'));
        $records = $this->dns->forUsers($selection->users());
        $total = count($records);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $records = array_slice($records, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $abilities = [];
        foreach ($records as $record) {
            $abilities[$record->user] ??= $policy->abilities($principal, $record->user);
        }

        $role = $principal->actingRole();

        return view('dns.index', [
            'records' => $records,
            'abilities' => $abilities,
            'showOwner' => $selection->showOwner(),
            'everyUser' => $selection->everyUser,
            'owners' => $selection->owners,
            'owner' => $selection->owner,
            'canCreate' => Capabilities::allows($role, 'dns.create') && ! $principal->isReadOnly(),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => array_filter([
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d DNS zones?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'rebuild' => $role === Role::Admin ? ['label' => __('Rebuild'), 'icon' => 'refresh'] : null,
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d DNS zones? Their records are removed.')],
            ]),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request, Principal $principal, DnsPolicy $policy, OwnerList $ownerList): View
    {
        $owner = $ownerList->forCreate($request->query('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        $canTemplates = $policy->updateTemplates($principal, $owner);
        $ips = $this->dns->userIps($owner);

        return view('dns.create', [
            'owner' => $owner,
            'owners' => $ownerList->pickable(),
            'ownerIsAdmin' => $ownerList->isAdmin($owner),
            'ips' => $ips,
            'templates' => $canTemplates ? $this->dns->templates() : null,
            'dnssec' => $this->dns->dnssecSupported(),
            'defaults' => [
                'ip' => $ips[0] ?? '',
                'template' => $this->dns->ownerTemplate($owner),
                'exp' => DnsService::defaultExpiry(),
                'ttl' => DnsService::DEFAULT_TTL,
                'ns' => $this->dns->nameServers($owner),
            ],
        ]);
    }

    public function store(StoreDnsZoneRequest $request, Principal $principal, DnsPolicy $policy, OwnerList $ownerList): RedirectResponse
    {
        $owner = $ownerList->forCreate($request->input('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        $canTemplates = $policy->updateTemplates($principal, $owner);
        $zone = $request->zone($this->dns->dnssecSupported());
        $this->actions->assertTemplate($canTemplates, $zone['template']);

        $result = $this->dns->apply($this->dns->planCreate($owner, $zone, $canTemplates, $this->dns->ownerTemplate($owner)));
        $records = redirect()->route('dns.records.index', ['user' => $owner, 'domain' => $zone['domain']]);
        if (! $result->ok()) {
            // The zone exists once its first step ran: show it with the failed step.
            return $result->changed()
                ? $records->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error)
                : back()->withInput()->with('error', $result->error);
        }

        return $records->with('ok', sprintf(__('DNS zone %s has been created successfully.'), $zone['domain']));
    }

    public function edit(string $user, string $domain, Principal $principal, DnsPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $zone = $this->dns->find($user, $domain);
        $can = $policy->abilities($principal, $user);

        return view('dns.edit', [
            'zone' => $zone,
            'can' => $can,
            'ips' => $this->dns->userIps($user),
            'templates' => $can['updateTemplates'] ? $this->dns->templates() : null,
            'dnssec' => $this->dns->dnssecSupported(),
        ]);
    }

    public function update(UpdateDnsZoneRequest $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $zone = $this->dns->find($user, $domain);

        $canTemplates = $policy->updateTemplates($principal, $user);
        $input = $request->zone($this->dns->dnssecSupported());
        if ($input['template'] !== $zone->template) {
            $this->actions->assertTemplate($canTemplates, $input['template']);
        }

        $result = $this->dns->apply($this->dns->planUpdate($zone, $input, $canTemplates));
        $back = redirect()->route('dns.edit', ['user' => $user, 'domain' => $domain]);
        if (! $result->ok()) {
            return $back->withInput()
                ->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error);
        }

        return $back->with('ok', __('Changes have been saved.'));
    }

    /** The public DNSSEC key of a zone (web/list/dns?domain=&action=). */
    public function dnssec(string $user, string $domain, Principal $principal, DnsPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $zone = $this->dns->find($user, $domain);

        return view('dns.dnssec', [
            'zone' => $zone,
            'key' => $zone->dnssec ? $this->dns->dnssecKey($user, $domain) : null,
        ]);
    }
}
