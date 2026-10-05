<?php

namespace App\Http\Requests\Dns;

/**
 * Edit a DNS zone (web/edit/dns): IP, template, SOA, expiration date, TTL
 * and DNSSEC. The domain cannot change.
 */
final class UpdateDnsZoneRequest extends DnsZoneRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['exp', 'ttl'] as $field) {
            $rules[$field][0] = 'required';
        }

        return [
            ...$rules,
            'soa' => ['required', 'string', 'max:253', self::WORD],
        ];
    }

    /**
     * @return array{ip: string, template: string, soa: string, exp: string, ttl: string, dnssec: bool|null}
     */
    public function zone(bool $dnssecField): array
    {
        return [
            'ip' => $this->text('ip'),
            'template' => $this->text('template'),
            'soa' => $this->text('soa'),
            'exp' => $this->text('exp'),
            'ttl' => $this->text('ttl'),
            'dnssec' => $dnssecField ? $this->dnssec() : null,
        ];
    }
}
