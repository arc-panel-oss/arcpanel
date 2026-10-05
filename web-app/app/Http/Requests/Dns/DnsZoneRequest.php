<?php

namespace App\Http\Requests\Dns;

use App\Services\Dns\DnsService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The fields shared by web/add/dns and web/edit/dns. The v-* commands check
 * the formats (is_format_valid ip, exp, ttl, soa, template); the request
 * keeps out line breaks and slashes and gives the legacy message of an
 * empty field.
 */
abstract class DnsZoneRequest extends FormRequest
{
    /** No line breaks, NUL bytes or blanks in a value that goes into a zone file. */
    protected const WORD = 'not_regex:/[\s\x00]/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip' => ['required', 'string', 'max:64', self::WORD],
            'template' => ['nullable', 'string', 'max:100', 'regex:/^[\w.-]+$/'],
            'exp' => ['nullable', 'string', 'max:32', self::WORD],
            'ttl' => ['nullable', 'string', 'max:16', self::WORD],
            'dnssec' => ['nullable', 'in:yes,1'],
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
            'domain' => __('Domain'),
            'ip' => __('IP Address'),
            'template' => __('Template'),
            'soa' => __('SOA'),
            'exp' => __('Expiration Date'),
            'ttl' => __('TTL'),
        ];
    }

    protected function text(string $field): string
    {
        return trim((string) $this->input($field, ''));
    }

    /** @return list<string> NS1 … NS8, empty fields left out */
    public function nameServers(): array
    {
        $servers = array_map(fn ($server) => trim((string) $server), (array) $this->input('ns', []));

        return array_slice(array_values(array_filter($servers, fn (string $server) => $server !== '')), 0, DnsService::NAME_SERVERS);
    }

    public function dnssec(): bool
    {
        return $this->filled('dnssec');
    }
}
