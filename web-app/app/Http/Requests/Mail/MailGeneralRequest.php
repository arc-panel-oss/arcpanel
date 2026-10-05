<?php

namespace App\Http\Requests\Mail;

/** General tab of a mail domain (web/edit/mail). */
final class MailGeneralRequest extends MailTabRequest
{
    public function tab(): string
    {
        return 'general';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'webmail' => ['nullable', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'catchall' => ['nullable', 'string', 'max:254', self::WORD],
            'rate' => ['nullable', 'regex:/^\d{1,9}$/'],
            'antispam' => ['nullable', 'boolean'],
            'reject' => ['nullable', 'boolean'],
            'antivirus' => ['nullable', 'boolean'],
            'dkim' => ['nullable', 'boolean'],
        ];
    }

    /**
     * $rateLimit: whether the actor may change the rate limit; otherwise
     * the field is disabled and changes nothing.
     *
     * @return array{webmail: string|null, catchall: string, rate: string|null, antispam: bool, reject: bool, antivirus: bool, dkim: bool}
     */
    public function values(bool $rateLimit): array
    {
        return [
            'webmail' => $this->filled('webmail') ? $this->text('webmail') : null,
            'catchall' => $this->text('catchall'),
            'rate' => $rateLimit ? $this->text('rate') : null,
            'antispam' => $this->boolean('antispam'),
            'reject' => $this->boolean('reject'),
            'antivirus' => $this->boolean('antivirus'),
            'dkim' => $this->boolean('dkim'),
        ];
    }
}
