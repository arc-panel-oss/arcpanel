<?php

namespace App\Http\Requests\Mail;

/** SMTP relay tab of a mail domain (web/edit/mail). The password is write-only. */
final class MailRelayRequest extends MailTabRequest
{
    public function tab(): string
    {
        return 'relay';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::relayRules();
    }

    /** @return array{relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: string} */
    public function values(): array
    {
        return $this->relayValues();
    }
}
