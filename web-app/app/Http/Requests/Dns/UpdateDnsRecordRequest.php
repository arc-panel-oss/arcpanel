<?php

namespace App\Http\Requests\Dns;

/**
 * Edit a DNS record (web/edit/dns?record_id=): the record fields and its id.
 */
final class UpdateDnsRecordRequest extends DnsRecordRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'id' => ['nullable', 'string', 'regex:/^[1-9]\d{0,8}$/'],
        ];
    }

    /** The new id, '' to keep the current one. */
    public function newId(): string
    {
        return trim((string) $this->input('id', ''));
    }
}
