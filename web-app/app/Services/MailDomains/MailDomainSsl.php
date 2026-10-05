<?php

namespace App\Services\MailDomains;

/**
 * Certificate of a mail domain (`v-list-mail-domain-ssl USER DOMAIN json`).
 * The private key stays on the server: it is kept here only to resubmit it
 * with a new certificate, and never rendered.
 */
final class MailDomainSsl
{
    public function __construct(
        public readonly string $certificate,
        private readonly string $key,
        public readonly string $ca,
        public readonly string $subject,
        public readonly string $aliases,
        public readonly string $notBefore,
        public readonly string $notAfter,
        public readonly string $signature,
        public readonly string $pubKey,
        public readonly string $issuer,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $value = fn (string $key): string => (string) ($row[$key] ?? '');

        return new self(
            certificate: $value('CRT'),
            key: $value('KEY'),
            ca: $value('CA'),
            subject: $value('SUBJECT'),
            aliases: $value('ALIASES'),
            notBefore: $value('NOT_BEFORE'),
            notAfter: $value('NOT_AFTER'),
            signature: $value('SIGNATURE'),
            pubKey: $value('PUB_KEY'),
            issuer: $value('ISSUER'),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function __debugInfo(): array
    {
        return ['subject' => $this->subject, 'notAfter' => $this->notAfter, 'key' => '******'];
    }
}
