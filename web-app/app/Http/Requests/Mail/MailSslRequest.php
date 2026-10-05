<?php

namespace App\Http\Requests\Mail;

use App\Services\MailDomains\MailDomainRecord;
use Illuminate\Validation\Rule;

/** SSL tab of a mail domain (web/edit/mail). */
final class MailSslRequest extends MailTabRequest
{
    public function tab(): string
    {
        return 'ssl';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ssl' => ['nullable', 'boolean'],
            'letsencrypt' => ['nullable', 'boolean'],
            'key_algo' => ['nullable', Rule::in(MailDomainRecord::KEY_ALGORITHMS)],
            'certificate' => ['nullable', 'string', 'max:65536'],
            'key' => ['nullable', 'string', 'max:65536'],
            'ca' => ['nullable', 'string', 'max:65536'],
        ];
    }

    /** @return array{ssl: bool, letsencrypt: bool, key_algo: string, certificate: ?string, key: ?string, ca: ?string} */
    public function values(): array
    {
        return [
            'ssl' => $this->boolean('ssl'),
            'letsencrypt' => $this->boolean('letsencrypt'),
            // web/edit/mail: ecdsa-256 when nothing (valid) was posted
            'key_algo' => (string) ($this->input('key_algo') ?: 'ecdsa-256'),
            'certificate' => $this->input('certificate'),
            'key' => $this->input('key'),
            'ca' => $this->input('ca'),
        ];
    }
}
