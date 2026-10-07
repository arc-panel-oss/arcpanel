<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Dns\DnsRecordRecord;
use App\Services\Dns\DnsService;
use Illuminate\Validation\Rule;

/**
 * PATCH /users/{user}/dns-zones/{domain}/records/{id}. The id does not change.
 * Fields left out keep their value.
 */
final class UpdateDnsRecordRequest extends StoreDnsRecordRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:253', 'not_regex:/[\r\n\x00]/'],
            'type' => ['sometimes', Rule::in(DnsService::RECORD_TYPES)],
            'value' => ['sometimes', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
            'priority' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'ttl' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /**
     * The record after this change.
     *
     * @return array{record: string, type: string, value: string, priority: string, ttl: string}
     */
    public function merged(DnsRecordRecord $current): array
    {
        $fields = $current->fields();
        if ($this->exists('name')) {
            $fields['record'] = trim((string) $this->input('name'));
        }
        if ($this->exists('type')) {
            $fields['type'] = trim((string) $this->input('type'));
        }
        if ($this->exists('value')) {
            $fields['value'] = trim((string) $this->input('value'));
        }
        if ($this->exists('priority')) {
            $fields['priority'] = $this->input('priority') === null ? '' : (string) $this->integer('priority');
        }
        if ($this->exists('ttl')) {
            $fields['ttl'] = $this->input('ttl') === null ? '' : (string) $this->integer('ttl');
        }

        return $fields;
    }
}
