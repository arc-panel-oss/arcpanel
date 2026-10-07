<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Dns\DnsService;
use Illuminate\Validation\Rule;

/**
 * POST /users/{user}/dns-zones/{domain}/records. The name is lowercased
 * and the type uppercased, as the command stores them.
 */
class StoreDnsRecordRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => strtolower(trim($this->input('name')))]);
        }
        if (is_string($this->input('type'))) {
            $this->merge(['type' => strtoupper(trim($this->input('type')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The record name, `@` for the zone itself. */
            'name' => ['required', 'string', 'max:253', 'not_regex:/[\r\n\x00]/'],
            /** One of the DNS record types. */
            'type' => ['required', Rule::in(DnsService::RECORD_TYPES)],
            /** The record value. */
            'value' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
            /** MX and SRV only. */
            'priority' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            /** Seconds. Null or left out keeps the zone's TTL. */
            'ttl' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /**
     * The fields `v-add-dns-record` takes.
     *
     * @return array{record: string, type: string, value: string, priority: string, ttl: string}
     */
    public function fields(): array
    {
        return [
            'record' => trim((string) $this->input('name')),
            'type' => trim((string) $this->input('type')),
            'value' => trim((string) $this->input('value')),
            'priority' => $this->exists('priority') && $this->input('priority') !== null ? (string) $this->integer('priority') : '',
            'ttl' => $this->exists('ttl') && $this->input('ttl') !== null ? (string) $this->integer('ttl') : '',
        ];
    }
}
