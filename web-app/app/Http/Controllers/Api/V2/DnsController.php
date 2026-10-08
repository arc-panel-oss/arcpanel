<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreDnsRecordRequest;
use App\Http\Requests\Api\V2\StoreDnsZoneRequest;
use App\Http\Requests\Api\V2\UpdateDnsRecordRequest;
use App\Http\Requests\Api\V2\UpdateDnsZoneRequest;
use App\Http\Resources\V2\DnsRecordResource;
use App\Http\Resources\V2\DnsZoneDnssecResource;
use App\Http\Resources\V2\DnsZoneResource;
use App\Policies\DnsPolicy;
use App\Services\Dns\DnsActions;
use App\Services\Dns\DnsRecordRecord;
use App\Services\Dns\DnsService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * DNS zones and records of a user (E34 batch 2 spec §6.1).
 *
 * @tags DNS
 */
final class DnsController extends Controller
{
    public function __construct(
        private readonly DnsService $dns,
        private readonly DnsActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the DNS zones of a user
     *
     * @operationId listDnsZones
     */
    public function index(Request $request, string $user, Principal $principal, DnsPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->dns->forUser($user)));

        return DnsZoneResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a DNS zone
     *
     * TTL 14400 and an expiry one year ahead when left out; only values that
     * differ add a step. A zone the user has answers 409 `E_EXISTS`.
     *
     * @operationId createDnsZone
     *
     * @status 201
     */
    public function store(StoreDnsZoneRequest $request, string $user, Principal $principal, DnsPolicy $policy): DnsZoneResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $domain = (string) $request->input('domain');
        if (array_key_exists($domain, $this->dns->forUser($user))) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('DNS domain %s exists.'), $domain), 409);
        }
        $this->assertDnssec($request->exists('dnssec'));
        $templates = $this->assertTemplateAllowed($request->exists('template') ? (string) $request->input('template', '') : '', $user, $principal, $policy);

        $ip = $this->address($user, $request->exists('ip') ? (string) $request->input('ip') : null);
        $zone = [
            'domain' => $domain,
            'ip' => $ip,
            'ns' => $request->exists('nameservers') ? array_values($request->input('nameservers')) : $this->dns->nameServers($user),
            'dnssec' => $request->boolean('dnssec'),
            'template' => trim((string) $request->input('template', '')),
            'exp' => $request->exists('expiry') ? (string) $request->input('expiry') : DnsService::defaultExpiry(),
            'ttl' => $request->exists('ttl') ? (string) $request->integer('ttl') : DnsService::DEFAULT_TTL,
        ];
        $steps = $this->dns->planCreate($user, $zone, $templates, $this->dns->ownerTemplate($user));

        if ($preview = $this->gate->execute($steps, $this->dns->apply(...))) {
            return $preview;
        }

        return (new DnsZoneResource($this->dns->find($user, $domain)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.dns-zones.show', ['user' => $user, 'domain' => $domain]));
    }

    /**
     * Get a DNS zone
     *
     * @operationId getDnsZone
     */
    public function show(string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsZoneResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new DnsZoneResource($this->dns->find($user, $domain));
    }

    /**
     * Update a DNS zone
     *
     * The IP address, template, TTL, SOA, expiry and DNSSEC. Fields left out
     * keep their value.
     *
     * @operationId updateDnsZone
     */
    public function update(UpdateDnsZoneRequest $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsZoneResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $zone = $this->dns->find($user, $domain);
        $this->assertDnssec($request->exists('dnssec'));
        $template = $request->exists('template') ? trim((string) $request->input('template', '')) : $zone->template;
        $templates = $this->assertTemplateAllowed($request->exists('template') ? $template : '', $user, $principal, $policy);

        $input = [
            'ip' => $request->exists('ip') ? $this->address($user, (string) $request->input('ip')) : $zone->ip,
            'template' => $template,
            'soa' => $request->exists('soa') ? trim((string) $request->input('soa')) : $zone->soa,
            'exp' => $request->exists('expiry') ? (string) $request->input('expiry') : $zone->exp,
            'ttl' => $request->exists('ttl') ? (string) $request->integer('ttl') : $zone->ttl,
            'dnssec' => $request->exists('dnssec') ? $request->boolean('dnssec') : null,
        ];

        return $this->gate->execute($this->dns->planUpdate($zone, $input, $templates), $this->dns->apply(...))
            ?? new DnsZoneResource($this->dns->find($user, $domain));
    }

    /**
     * Delete a DNS zone
     *
     * @operationId deleteDnsZone
     *
     * @status 204
     */
    public function destroy(string $user, string $domain, Principal $principal, DnsPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->dns->find($user, $domain);
        $steps = [new Step(sprintf(__('Delete DNS zone %s'), $domain), 'v-delete-dns-domain', [$user, $domain])];

        return $this->gate->execute($steps, $this->dns->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a DNS zone
     *
     * A suspended zone answers as it is.
     *
     * @operationId suspendDnsZone
     */
    public function suspend(string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsZoneResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, true, $principal, $policy);
    }

    /**
     * Unsuspend a DNS zone
     *
     * @operationId unsuspendDnsZone
     */
    public function unsuspend(string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsZoneResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, false, $principal, $policy);
    }

    /**
     * Rebuild a DNS zone
     *
     * Administrators only.
     *
     * @operationId rebuildDnsZone
     */
    public function rebuild(string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsZoneResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->rebuild($principal, $user), 403);
        $this->dns->find($user, $domain);
        $steps = [new Step(sprintf(__('Rebuild DNS zone %s'), $domain), 'v-rebuild-dns-domain', [$user, $domain])];

        return $this->gate->execute($steps, $this->dns->apply(...)) ?? new DnsZoneResource($this->dns->find($user, $domain));
    }

    /**
     * Get the DNSSEC public key of a zone
     *
     * 404 when the zone has no DNSSEC.
     *
     * @operationId getDnsZoneDnssec
     */
    public function dnssec(string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsZoneDnssecResource
    {
        $this->assertOwner($user, $principal, $policy);
        $zone = $this->dns->find($user, $domain);
        if (! $zone->dnssec) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('DNS zone %s has no DNSSEC.'), $domain), 404);
        }
        $key = $this->dns->dnssecKey($user, $domain);

        return new DnsZoneDnssecResource([
            'domain' => $domain,
            'record' => $key['record'],
            'key_tag' => $key['keytag'],
            'flag' => $key['flag'],
            'algorithm' => preg_replace('/\s.*$/', '', $key['algorithm']) ?? $key['algorithm'],
            'key' => $key['key'],
            'ds' => $key['ds'],
        ]);
    }

    /**
     * List the records of a DNS zone
     *
     * `name` and `type` match exactly.
     *
     * @operationId listDnsRecords
     */
    public function records(Request $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $this->dns->find($user, $domain);
        if (is_string($request->query('type'))) {
            $request->query->set('type', strtoupper($request->query('type')));
        }
        $filters = $request->validate([
            /** Exact record name. */
            'name' => ['sometimes', 'string', 'max:253'],
            /** Exact record type. */
            'type' => ['sometimes', Rule::in(DnsService::RECORD_TYPES)],
        ]);
        $name = (string) ($filters['name'] ?? '');
        $type = (string) ($filters['type'] ?? '');
        $records = array_values(array_filter(
            $this->dns->records($user, $domain),
            fn (DnsRecordRecord $record) => ($name === '' || $record->record === $name) && ($type === '' || $record->type === $type),
        ));
        $page = $this->pages->slice($request, $records);

        return DnsRecordResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a DNS record
     *
     * The id is the next one after the highest the zone has. A record with
     * the same name, type, value and priority answers 409 `E_EXISTS` with
     * `existing`.
     *
     * @operationId createDnsRecord
     *
     * @status 201
     */
    public function storeRecord(StoreDnsRecordRequest $request, string $user, string $domain, Principal $principal, DnsPolicy $policy): DnsRecordResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $this->dns->find($user, $domain);
        $fields = $request->fields();
        $this->assertRecordNew($user, $domain, $fields);
        $id = $this->actions->nextRecordId($user, $domain);
        $steps = [$this->dns->planAddRecord($user, $domain, $fields, $id)];

        if ($preview = $this->gate->execute($steps, $this->dns->apply(...))) {
            return $preview;
        }

        return (new DnsRecordResource($this->dns->findRecord($user, $domain, $id)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.dns-zones.records.show', ['user' => $user, 'domain' => $domain, 'id' => $id]));
    }

    /**
     * Get a DNS record
     *
     * @operationId getDnsRecord
     */
    public function showRecord(string $user, string $domain, string $id, Principal $principal, DnsPolicy $policy): DnsRecordResource
    {
        $this->assertOwner($user, $principal, $policy);
        $this->dns->find($user, $domain);

        return new DnsRecordResource($this->dns->findRecord($user, $domain, $id));
    }

    /**
     * Update a DNS record
     *
     * The id does not change.
     *
     * @operationId updateDnsRecord
     */
    public function updateRecord(UpdateDnsRecordRequest $request, string $user, string $domain, string $id, Principal $principal, DnsPolicy $policy): DnsRecordResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $this->dns->find($user, $domain);
        $record = $this->dns->findRecord($user, $domain, $id);

        return $this->gate->execute($this->dns->planUpdateRecord($record, $request->merged($record), ''), $this->dns->apply(...))
            ?? new DnsRecordResource($this->dns->findRecord($user, $domain, $id));
    }

    /**
     * Delete a DNS record
     *
     * @operationId deleteDnsRecord
     *
     * @status 204
     */
    public function destroyRecord(string $user, string $domain, string $id, Principal $principal, DnsPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $this->dns->find($user, $domain);
        $this->dns->findRecord($user, $domain, $id);
        $steps = [new Step(__('Delete the DNS record'), 'v-delete-dns-record', [$user, $domain, $id])];

        return $this->gate->execute($steps, $this->dns->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a DNS record
     *
     * @operationId suspendDnsRecord
     */
    public function suspendRecord(string $user, string $domain, string $id, Principal $principal, DnsPolicy $policy): DnsRecordResource|JsonResponse
    {
        return $this->setRecordSuspended($user, $domain, $id, true, $principal, $policy);
    }

    /**
     * Unsuspend a DNS record
     *
     * @operationId unsuspendDnsRecord
     */
    public function unsuspendRecord(string $user, string $domain, string $id, Principal $principal, DnsPolicy $policy): DnsRecordResource|JsonResponse
    {
        return $this->setRecordSuspended($user, $domain, $id, false, $principal, $policy);
    }

    private function setSuspended(string $user, string $domain, bool $suspend, Principal $principal, DnsPolicy $policy): DnsZoneResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $zone = $this->dns->find($user, $domain);
        $steps = $zone->suspended === $suspend ? [] : [new Step(
            $suspend ? sprintf(__('Suspend DNS zone %s'), $domain) : sprintf(__('Unsuspend DNS zone %s'), $domain),
            $suspend ? 'v-suspend-dns-domain' : 'v-unsuspend-dns-domain',
            [$user, $domain],
        )];

        return $this->gate->execute($steps, $this->dns->apply(...)) ?? new DnsZoneResource($this->dns->find($user, $domain));
    }

    private function setRecordSuspended(string $user, string $domain, string $id, bool $suspend, Principal $principal, DnsPolicy $policy): DnsRecordResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $this->dns->find($user, $domain);
        $record = $this->dns->findRecord($user, $domain, $id);
        $steps = $record->suspended === $suspend ? [] : [new Step(
            $suspend ? __('Suspend the DNS record') : __('Unsuspend the DNS record'),
            $suspend ? 'v-suspend-dns-record' : 'v-unsuspend-dns-record',
            [$user, $domain, $id],
        )];

        return $this->gate->execute($steps, $this->dns->apply(...)) ?? new DnsRecordResource($this->dns->findRecord($user, $domain, $id));
    }

    /** A user the principal may see that exists; 404 otherwise. */
    private function assertOwner(string $user, Principal $principal, DnsPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->actions->ownerExists($user), 404);
    }

    /** Sending `dnssec` on a server without it is a validation error. */
    private function assertDnssec(bool $sent): void
    {
        if ($sent && ! $this->dns->dnssecSupported()) {
            throw ValidationException::withMessages(['dnssec' => __('DNSSEC is not supported on this server.')]);
        }
    }

    /** A template in the body needs the right to change templates, and must exist. */
    private function assertTemplateAllowed(string $template, string $user, Principal $principal, DnsPolicy $policy): bool
    {
        if ($template === '') {
            return false;
        }
        if (! $policy->updateTemplates($principal, $user)) {
            throw new ApiException(ErrorCode::Forbidden, __('This account may not change DNS templates.'), 403);
        }
        $this->actions->assertTemplate(true, $template);

        return true;
    }

    /** One of the user's addresses; the first one when $ip is null. */
    private function address(string $user, ?string $ip): string
    {
        $ips = $this->dns->userIps($user);
        $chosen = $ip ?? ($ips[0] ?? '');
        if ($chosen === '' || ! in_array($chosen, $ips, true)) {
            throw ValidationException::withMessages(['ip' => __('Please select a valid IP address.')]);
        }

        return $chosen;
    }

    /**
     * A record with the same name, type, value and priority answers 409,
     * both sides in the form v-add-dns-record stores them (D21).
     *
     * @param  array{record: string, type: string, value: string, priority: string, ttl: string}  $fields
     */
    private function assertRecordNew(string $user, string $domain, array $fields): void
    {
        $new = DnsService::storedRecord($fields);
        foreach ($this->dns->records($user, $domain) as $record) {
            if (DnsService::storedRecord($record->fields()) === $new) {
                throw new ApiException(
                    ErrorCode::Exists,
                    __('DNS record exists.'),
                    409,
                    existing: route('api.v2.dns-zones.records.show', ['user' => $user, 'domain' => $domain, 'id' => $record->id], false),
                );
            }
        }
    }
}
