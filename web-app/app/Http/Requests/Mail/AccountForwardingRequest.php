<?php

namespace App\Http\Requests\Mail;

/** Aliases and forwarding tab of a mail account (web/edit/mail?account=). */
final class AccountForwardingRequest extends MailTabRequest
{
    public function tab(): string
    {
        return 'forwarding';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'aliases' => ['nullable', 'string', 'max:10000'],
            'forward' => ['nullable', 'string', 'max:10000'],
            'blackhole' => ['nullable', 'boolean'],
            'fwd_only' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{aliases: list<string>, forwards: list<string>, blackhole: bool, fwd_only: bool} */
    public function values(): array
    {
        return [
            'aliases' => $this->entries('aliases'),
            'forwards' => $this->boolean('blackhole') ? [] : $this->entries('forward'),
            'blackhole' => $this->boolean('blackhole'),
            'fwd_only' => $this->boolean('fwd_only'),
        ];
    }
}
