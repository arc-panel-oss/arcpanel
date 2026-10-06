<?php

namespace App\Http\Requests\Firewall;

use App\Services\Firewall\FirewallService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of web/add/firewall and web/edit/firewall. "0" is a valid port
 * (all ports); v-add-firewall-rule and v-change-firewall-rule check the
 * formats of the port and the address.
 */
final class FirewallRuleRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(FirewallService::ACTIONS)],
            'protocol' => ['required', 'string', Rule::in(FirewallService::PROTOCOLS)],
            'port' => ['required', 'string', 'max:255', $line],
            'ip' => ['required', 'string', 'max:255', $line],
            'comment' => ['nullable', 'string', 'max:255', $line],
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
            'action' => __('Action'),
            'protocol' => __('Protocol'),
            'port' => __('Port'),
            'ip' => __('IP Address'),
            'comment' => __('Comment'),
        ];
    }

    /** @return array{action: string, ip: string, port: string, protocol: string, comment: string} */
    public function rule(): array
    {
        return [
            'action' => (string) $this->input('action'),
            'ip' => trim((string) $this->input('ip')),
            'port' => (string) $this->input('port'),
            'protocol' => (string) $this->input('protocol'),
            'comment' => trim((string) $this->input('comment', '')),
        ];
    }
}
