<?php

namespace App\Http\Requests\Mail;

/** Autoreply tab of a mail account (web/edit/mail?account=). */
final class AccountAutoreplyRequest extends MailTabRequest
{
    public function tab(): string
    {
        return 'autoreply';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'autoreply' => ['nullable', 'boolean'],
            // is_autoreply_format_valid: under 10240 characters
            'message' => ['nullable', 'string', 'max:10000', 'not_regex:/\x00|\r(?!\n)/'],
        ];
    }

    /** @return array{autoreply: bool, message: string} */
    public function values(): array
    {
        return [
            'autoreply' => $this->boolean('autoreply'),
            'message' => str_replace("\r\n", "\n", (string) $this->input('message', '')),
        ];
    }
}
