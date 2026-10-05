<?php

namespace App\Http\Requests\Mail;

/** General tab of a mail account (web/edit/mail?account=): password, quota, rate limit, credentials mail. */
final class AccountGeneralRequest extends MailTabRequest
{
    public function tab(): string
    {
        return 'general';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'max:256', self::LINE, self::passwordRule()],
            ...self::quotaRules(),
            'rate' => ['nullable', 'regex:/^\d{1,9}$/'],
            'email' => ['nullable', 'email:filter', 'max:254'],
        ];
    }

    /** @return array{password: string, quota: string, rate: string|null} */
    public function values(bool $rateLimit): array
    {
        return [
            'password' => (string) $this->input('password', ''),
            'quota' => $this->quota(),
            'rate' => $rateLimit ? $this->text('rate') : null,
        ];
    }
}
