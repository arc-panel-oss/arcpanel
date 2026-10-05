<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a mail domain (web/add/mail) for the chosen owner. The domain is
 * lower-cased and loses a leading "www.", as the legacy page did.
 */
final class StoreMailDomainRequest extends FormRequest
{
    use MailFields;

    public function authorize(): bool
    {
        return true;
    }

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
            'webmail' => ['nullable', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'antispam' => ['nullable', 'boolean'],
            'reject' => ['nullable', 'boolean'],
            'antivirus' => ['nullable', 'boolean'],
            'dkim' => ['nullable', 'boolean'],
            ...self::relayRules(),
        ];
    }

    /**
     * @return array{domain: string, antispam: bool, antivirus: bool, dkim: bool, reject: bool, webmail: string, relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: string}
     */
    public function values(): array
    {
        return [
            'domain' => $this->text('domain'),
            'antispam' => $this->boolean('antispam'),
            'antivirus' => $this->boolean('antivirus'),
            'dkim' => $this->boolean('dkim'),
            'reject' => $this->boolean('reject'),
            'webmail' => $this->text('webmail'),
            ...$this->relayValues(),
        ];
    }
}
