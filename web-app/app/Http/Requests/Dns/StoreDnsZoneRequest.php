<?php

namespace App\Http\Requests\Dns;

use App\Services\Dns\DnsService;

/**
 * Add a DNS zone (web/add/dns) for the chosen owner. The domain is lower-cased
 * and loses a leading "www.", as the legacy page did.
 */
final class StoreDnsZoneRequest extends DnsZoneRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => strtolower((string) preg_replace('/^www\./i', '', trim($this->input('domain'))))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'domain' => ['required', 'string', 'max:253', 'not_regex:/[\s\/\x00]/'],
            ...parent::rules(),
            'ns' => ['nullable', 'array', 'max:'.DnsService::NAME_SERVERS],
            'ns.*' => ['nullable', 'string', 'max:253', self::WORD],
        ];
    }

    /**
     * @return array{domain: string, ip: string, ns: list<string>, dnssec: bool, template: string, exp: string, ttl: string}
     */
    public function zone(bool $dnssecField): array
    {
        return [
            'domain' => $this->text('domain'),
            'ip' => $this->text('ip'),
            'ns' => $this->nameServers(),
            'dnssec' => $dnssecField && $this->dnssec(),
            'template' => $this->text('template'),
            'exp' => $this->text('exp'),
            'ttl' => $this->text('ttl'),
        ];
    }
}
