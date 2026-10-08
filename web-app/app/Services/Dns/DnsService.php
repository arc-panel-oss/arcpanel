<?php

namespace App\Services\Dns;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DNS zones and records through the v-* commands, with the arguments and
 * order of the legacy pages (web/list/dns, web/add/dns, web/edit/dns,
 * web/bulk/dns, web/{suspend,unsuspend,delete}/dns).
 */
final class DnsService
{
    public const BULK_COMMANDS = [
        'suspend' => 'v-suspend-dns-domain',
        'unsuspend' => 'v-unsuspend-dns-domain',
        'rebuild' => 'v-rebuild-dns-domain',
        'delete' => 'v-delete-dns-domain',
    ];

    public const RECORD_BULK_COMMANDS = [
        'suspend' => 'v-suspend-dns-record',
        'unsuspend' => 'v-unsuspend-dns-record',
        'delete' => 'v-delete-dns-record',
    ];

    /** The record types of is_dns_type_format_valid (func/main.sh), in the order of the legacy select. */
    public const RECORD_TYPES = ['A', 'AAAA', 'CAA', 'CNAME', 'DNSKEY', 'DS', 'IPSECKEY', 'KEY', 'MX', 'NS', 'PTR', 'SPF', 'SRV', 'TLSA', 'TXT'];

    /** Types whose form shows the priority field. */
    public const PRIORITY_TYPES = ['MX', 'SRV'];

    /** Types whose value v-add-dns-record ends with a dot and IDN-encodes. */
    private const HOST_VALUE_TYPES = ['NS', 'CNAME', 'MX', 'PTR', 'SRV'];

    /** Types whose value v-add-dns-record never quotes, spaces or not. */
    private const UNQUOTED_TYPES = ['SRV', 'DNSKEY', 'DS', 'TLSA', 'KEY', 'IPSECKEY'];

    /** TTL of a new zone (web/add/dns). */
    public const DEFAULT_TTL = '14400';

    /** Name server fields of the zone form (NS1 … NS8). */
    public const NAME_SERVERS = 8;

    /** Algorithm numbers of a DNSKEY record and their names (web/list/dns/index.php). */
    private const ALGORITHMS = [
        '3' => '3 - DSA',
        '5' => '5 - RSA/SHA1',
        '6' => '6 - DSA-NSEC3-SHA1',
        '7' => '7 - RSA/SHA1-NSEC3-SHA1',
        '8' => '8 - RSA/SHA256',
        '10' => '10 - RSA/SHA512',
        '12' => '12 - ECC-GOST',
        '13' => '13 - ECDSAP256/SHA256',
        '14' => '14 - ECDSAP384/SHA384',
        '15' => '15 - ED25519/SHA512',
        '16' => '16 - ED448/SHA912',
    ];

    /** @var array<string, array<string, DnsZoneRecord>> per-request cache */
    private array $lists = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
        private readonly PlanRunner $plans,
    ) {}

    /** @return array<string, DnsZoneRecord> domain => zone */
    public function forUser(string $user): array
    {
        if (! isset($this->lists[$user])) {
            $records = [];
            foreach ($this->arc->json('v-list-dns-domains', [$user]) as $domain => $row) {
                if (is_string($domain) && is_array($row)) {
                    $records[$domain] = DnsZoneRecord::fromArray($user, $domain, $row);
                }
            }
            $this->lists[$user] = $records;
        }

        return $this->lists[$user];
    }

    /**
     * @param  list<string>  $users
     * @return list<DnsZoneRecord> sorted by domain, then owner
     */
    public function forUsers(array $users): array
    {
        $records = [];
        foreach ($users as $user) {
            array_push($records, ...array_values($this->forUser($user)));
        }
        usort($records, fn (DnsZoneRecord $a, DnsZoneRecord $b) => [$a->domain, $a->user] <=> [$b->domain, $b->user]);

        return $records;
    }

    /** One zone; 404 when the user has no such zone. */
    public function find(string $user, string $domain): DnsZoneRecord
    {
        return DnsZoneRecord::fromArray($user, $domain, $this->lookup->one('v-list-dns-domain', [$user, $domain], $domain));
    }

    /** @return list<DnsRecordRecord> the records of a zone in id order; 404 when the zone is missing */
    public function records(string $user, string $domain): array
    {
        $records = [];
        foreach ($this->lookup->decode('v-list-dns-records', [$user, $domain]) as $id => $row) {
            if (is_array($row)) {
                $records[] = DnsRecordRecord::fromArray($user, $domain, (string) $id, $row);
            }
        }
        usort($records, fn (DnsRecordRecord $a, DnsRecordRecord $b) => (int) $a->id <=> (int) $b->id);

        return $records;
    }

    /** One record (there is no v-list-dns-record): 404 when the zone has no such id. */
    public function findRecord(string $user, string $domain, string $id): DnsRecordRecord
    {
        foreach ($this->records($user, $domain) as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * The public DNSSEC key of a zone (`v-list-dnssec-public-key`) with the
     * labels of web/list/dns: key type from the flag, algorithm name.
     *
     * @return array{record: string, ds: string, key: string, type: string, keytag: string, flag: string, algorithm: string}
     */
    public function dnssecKey(string $user, string $domain): array
    {
        $row = $this->lookup->one('v-list-dnssec-public-key', [$user, $domain], $domain);
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return [
            'record' => $value('RECORD'),
            'ds' => $value('DS'),
            'key' => $value('KEY'),
            'type' => self::keyType($value('FLAG')),
            'keytag' => $value('KEYTAG'),
            'flag' => $value('FLAG'),
            'algorithm' => self::algorithm($value('ALGORITHM')),
        ];
    }

    /** "KSK (257)", "ZSK (256)", else empty. */
    public static function keyType(string $flag): string
    {
        return match ($flag) {
            '257' => 'KSK (257)',
            '256' => 'ZSK (256)',
            default => '',
        };
    }

    public static function algorithm(string $number): string
    {
        return self::ALGORITHMS[$number] ?? __('Unknown');
    }

    /** @return list<string> DNS templates of the server (`v-list-dns-templates`) */
    public function templates(): array
    {
        return array_values(array_filter(
            array_map(fn ($name) => is_string($name) ? $name : '', $this->arc->json('v-list-dns-templates', [], Options::allowFailure())),
            fn (string $name) => $name !== '',
        ));
    }

    /**
     * Addresses a zone may point to: the user's IPs, the NAT address where
     * there is one (templates/pages/add_dns.php).
     *
     * @return list<string>
     */
    public function userIps(string $user): array
    {
        $ips = [];
        foreach ($this->arc->json('v-list-user-ips', [$user], Options::allowFailure()) as $ip => $row) {
            $nat = is_array($row) ? trim((string) ($row['NAT'] ?? '')) : '';
            $address = $nat !== '' ? $nat : (string) $ip;
            if ($address !== '' && ! in_array($address, $ips, true)) {
                $ips[] = $address;
            }
        }

        return $ips;
    }

    /** @return list<string> the user's name servers (`v-list-user-ns`), the defaults of a new zone */
    public function nameServers(string $user): array
    {
        $servers = [];
        foreach ($this->arc->json('v-list-user-ns', [$user], Options::allowFailure()) as $server) {
            $server = is_string($server) ? trim(str_replace("'", '', $server)) : '';
            if ($server !== '') {
                $servers[] = $server;
            }
        }

        return array_slice($servers, 0, self::NAME_SERVERS);
    }

    /** The owner's DNS_TEMPLATE: v-add-dns-domain applies it, so only another template needs a step. */
    public function ownerTemplate(string $user): string
    {
        $record = $this->arc->json('v-list-user', [$user], Options::allowFailure())[$user] ?? [];

        return is_array($record) ? trim((string) ($record['DNS_TEMPLATE'] ?? '')) : '';
    }

    /** DNSSEC needs the arcpanel-zone cluster system (v-change-dns-domain-dnssec). */
    public function dnssecSupported(): bool
    {
        return $this->config->get('DNS_CLUSTER_SYSTEM') === 'arcpanel-zone' && $this->config->is('SUPPORT_DNSSEC');
    }

    /** Expiration date of a new zone: today + 1 year, as web/add/dns computes it. */
    public static function defaultExpiry(): string
    {
        return date('Y-m-d', (int) strtotime('+1 year', now()->getTimestamp()));
    }

    /**
     * web/add/dns: `v-add-dns-domain USER DOMAIN IP NS1 … NS8 no DNSSEC`, then
     * the template (when the actor may choose it and it is not the owner's
     * DNS_TEMPLATE), the expiration date and the TTL when they differ from
     * the defaults; DNS restarts once at the end.
     *
     * @param  array{domain: string, ip: string, ns: list<string>, dnssec: bool, template: string, exp: string, ttl: string}  $zone
     * @return list<Step>
     */
    public function planCreate(string $owner, array $zone, bool $templates, string $ownerTemplate): array
    {
        $domain = $zone['domain'];
        $servers = array_pad(array_slice(array_values($zone['ns']), 0, self::NAME_SERVERS), self::NAME_SERVERS, '');

        $steps = [new Step(__('Add the DNS zone'), 'v-add-dns-domain', [$owner, $domain, $zone['ip'], ...$servers, 'no', $zone['dnssec'] ? 'yes' : 'no'], ['dns'])];
        if ($templates && $zone['template'] !== '' && $zone['template'] !== $ownerTemplate) {
            $steps[] = new Step(__('Change the template'), 'v-change-dns-domain-tpl', [$owner, $domain, $zone['template'], 'no'], ['dns']);
        }
        if ($zone['exp'] !== '' && $zone['exp'] !== self::defaultExpiry()) {
            $steps[] = new Step(__('Change the expiration date'), 'v-change-dns-domain-exp', [$owner, $domain, $zone['exp'], 'no'], ['dns']);
        }
        if ($zone['ttl'] !== '' && $zone['ttl'] !== self::DEFAULT_TTL) {
            $steps[] = new Step(__('Change the TTL'), 'v-change-dns-domain-ttl', [$owner, $domain, $zone['ttl'], 'no'], ['dns']);
        }

        return $steps;
    }

    /**
     * web/edit/dns: one command per changed value in the legacy order (IP,
     * template, SOA, expiration date, TTL, DNSSEC), then one DNS restart.
     * $templates: the actor may change the template; a null DNSSEC means the
     * form did not show the field.
     *
     * @param  array{ip: string, template: string, soa: string, exp: string, ttl: string, dnssec: bool|null}  $input
     * @return list<Step>
     */
    public function planUpdate(DnsZoneRecord $zone, array $input, bool $templates): array
    {
        $args = fn (string $value) => [$zone->user, $zone->domain, $value, 'no'];
        $steps = [];
        if ($input['ip'] !== $zone->ip) {
            $steps[] = new Step(__('Change the IP address'), 'v-change-dns-domain-ip', $args($input['ip']), ['dns']);
        }
        if ($templates && $input['template'] !== '' && $input['template'] !== $zone->template) {
            $steps[] = new Step(__('Change the template'), 'v-change-dns-domain-tpl', $args($input['template']), ['dns']);
        }
        if ($input['soa'] !== $zone->soa) {
            $steps[] = new Step(__('Change the SOA'), 'v-change-dns-domain-soa', $args($input['soa']), ['dns']);
        }
        if ($input['exp'] !== $zone->exp) {
            $steps[] = new Step(__('Change the expiration date'), 'v-change-dns-domain-exp', $args($input['exp']), ['dns']);
        }
        if ($input['ttl'] !== $zone->ttl) {
            $steps[] = new Step(__('Change the TTL'), 'v-change-dns-domain-ttl', $args($input['ttl']), ['dns']);
        }
        if ($input['dnssec'] !== null && $input['dnssec'] !== $zone->dnssec) {
            $steps[] = new Step(__('Change DNSSEC'), 'v-change-dns-domain-dnssec', [$zone->user, $zone->domain, $input['dnssec'] ? 'yes' : 'no'], ['dns']);
        }

        return $steps;
    }

    /**
     * `v-add-dns-record USER DOMAIN RECORD TYPE VALUE PRIORITY '' yes TTL`:
     * the CLI picks the id, quotes, IDN-encodes and validates the value and
     * restarts DNS itself.
     *
     * @param  array{record: string, type: string, value: string, priority: string, ttl: string}  $fields
     * @param  string  $id  the record id; empty lets the command choose one. API v2 passes the next id (D21).
     */
    public function planAddRecord(string $user, string $domain, array $fields, string $id = ''): Step
    {
        return new Step(__('Add the DNS record'), 'v-add-dns-record',
            [$user, $domain, $fields['record'], $fields['type'], $fields['value'], $fields['priority'], $id, 'yes', $fields['ttl']]);
    }

    /**
     * A record in the form `v-add-dns-record` stores it, to compare a new
     * record with the stored ones (D21). Mirrors bin/v-add-dns-record: the
     * name is lowercased; MX and SRV without a priority get 10 and every
     * other type drops it; NS, CNAME, MX, PTR and SRV values end with a dot
     * and are IDN-encoded; double quotes are removed from every value but
     * CAA, and a value with a space or `;` is quoted again, except for SRV,
     * DNSKEY, DS, TLSA, KEY and IPSECKEY. Host names compare without case.
     *
     * @param  array{record: string, type: string, value: string, priority: string, ttl?: string}  $fields
     * @return array{record: string, type: string, value: string, priority: string}
     */
    public static function storedRecord(array $fields): array
    {
        $type = strtoupper(trim($fields['type']));
        $record = mb_strtolower(trim($fields['record']));
        $value = trim($fields['value']);
        $priority = trim($fields['priority']);

        if (! in_array($type, self::PRIORITY_TYPES, true)) {
            $priority = '';
        } elseif ($priority === '') {
            $priority = '10';
        }

        if (in_array($type, self::HOST_VALUE_TYPES, true)) {
            if (! str_ends_with($value, '.')) {
                $value .= '.';
            }
            $value = self::idnAscii($value);
            $record = self::idnAscii($record);
        } elseif (str_contains($record, 'xn--') && function_exists('idn_to_utf8')) {
            $record = idn_to_utf8($record, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $record;
        }

        if ($type !== 'CAA') {
            $value = str_replace('"', '', $value);
            if (! in_array($type, self::UNQUOTED_TYPES, true) && preg_match('/[;\s]/', $value) === 1) {
                $value = '"'.$value.'"';
            }
        }

        return ['record' => $record, 'type' => $type, 'value' => $value, 'priority' => $priority];
    }

    /** `idn2` output: the ASCII form, lowercased. */
    private static function idnAscii(string $name): string
    {
        if (preg_match('/[^\x00-\x7F]/', $name) === 1 && function_exists('idn_to_ascii')) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        return strtolower($name);
    }

    /**
     * web/edit/dns?record_id=: `v-change-dns-record` when a field changed,
     * `v-change-dns-record-id` when the id changed, then a DNS restart.
     *
     * @param  array{record: string, type: string, value: string, priority: string, ttl: string}  $fields
     * @return list<Step>
     */
    public function planUpdateRecord(DnsRecordRecord $record, array $fields, string $newId): array
    {
        $steps = [];
        $changed = array_filter($record->fields(), fn (string $value, string $key) => ($fields[$key] ?? '') !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $steps[] = new Step(__('Change the DNS record'), 'v-change-dns-record', [
                $record->user, $record->domain, $record->id, $fields['record'], $fields['type'], $fields['value'], $fields['priority'], 'yes', $fields['ttl'],
            ], ['dns']);
        }
        if ($newId !== '' && $newId !== $record->id) {
            $steps[] = new Step(__('Change the record ID'), 'v-change-dns-record-id', [$record->user, $record->domain, $record->id, $newId], ['dns']);
        }

        return $steps;
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $this->lists = [];

        return $this->plans->apply($steps);
    }

    /**
     * web/bulk/dns: each zone with RESTART=no, then one `v-restart-dns`.
     *
     * @param  list<array{0: string, 1: string}>  $items  [owner, domain]
     */
    public function bulk(string $action, array $items): BulkResult
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $this->lists = [];

        return $this->runner->run($items, fn (array $item) => [$item[1], $command, [$item[0], $item[1], 'no']], ['v-restart-dns']);
    }

    /** web/{suspend,unsuspend,delete}/dns: one zone, the command restarts DNS itself. */
    public function single(string $action, string $user, string $domain): BulkResult
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $this->lists = [];

        return $this->runner->run([[$user, $domain]], fn (array $item) => [$item[1], $command, [$item[0], $item[1]]]);
    }

    /**
     * web/bulk/dns?domain=: each record with RESTART=no, then one `v-restart-dns`.
     *
     * @param  list<string>  $ids
     */
    public function bulkRecords(string $action, string $user, string $domain, array $ids): BulkResult
    {
        $command = self::RECORD_BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");

        return $this->runner->run($ids, fn (string $id) => [$id, $command, [$user, $domain, $id, 'no']], ['v-restart-dns']);
    }

    /** web/{suspend,unsuspend,delete}/dns?record_id=: one record, the command restarts DNS itself. */
    public function singleRecord(string $action, string $user, string $domain, string $id): BulkResult
    {
        $command = self::RECORD_BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");

        return $this->runner->run([$id], fn (string $item) => [$item, $command, [$user, $domain, $item]]);
    }
}
