<?php

namespace App\Http\Requests\Dns;

use App\Services\Dns\DnsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a DNS record (web/add/dns?domain=). v-add-dns-record and
 * v-change-dns-record quote, IDN-encode and validate the record and its
 * value; the request only asks for the required fields and keeps out line
 * breaks.
 */
class DnsRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $line = 'not_regex:/[\r\n\x00]/';

        return [
            'record' => ['required', 'string', 'max:253', $line],
            'type' => ['required', Rule::in(DnsService::RECORD_TYPES)],
            'value' => ['required', 'string', 'max:4096', $line],
            'priority' => ['nullable', 'string', 'max:16', 'not_regex:/[\s\x00]/'],
            'ttl' => ['nullable', 'string', 'max:16', 'not_regex:/[\s\x00]/'],
        ];
    }

    /** @return array<string, string> the legacy message of an empty field */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->attributes() as $field => $label) {
            $messages[$field.'.required'] = sprintf(__('Field "%s" can not be blank.'), $label);
        }

        return $messages;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'record' => __('Record'),
            'type' => __('Type'),
            'value' => __('IP or Value'),
            'priority' => __('Priority'),
            'ttl' => __('TTL'),
            'id' => __('Record ID'),
        ];
    }

    /**
     * The record fields in command order; an empty TTL keeps the zone's default.
     *
     * @return array{record: string, type: string, value: string, priority: string, ttl: string}
     */
    public function fields(): array
    {
        $text = fn (string $field): string => trim((string) $this->input($field, ''));

        return [
            'record' => $text('record'),
            'type' => $text('type'),
            'value' => $text('value'),
            'priority' => $text('priority'),
            'ttl' => $text('ttl'),
        ];
    }
}
