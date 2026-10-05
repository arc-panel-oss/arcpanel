<?php

namespace App\Services\WebDomains;

use DateTimeImmutable;

/**
 * Certificate of a web domain (`v-list-web-domain-ssl USER DOMAIN json`).
 * The private key stays on the server: it is kept here only to resubmit it
 * with a new certificate, and never rendered.
 */
final class WebDomainSsl
{
    public function __construct(
        public readonly string $certificate,
        private readonly string $key,
        public readonly string $ca,
        public readonly string $subject,
        public readonly string $aliases,
        public readonly string $notBefore,
        public readonly string $notAfter,
        public readonly string $issuer,
        public readonly string $signature,
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
            issuer: $value('ISSUER'),
            signature: $value('SIGNATURE'),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        if ($this->notAfter === '') {
            return null;
        }
        $time = strtotime($this->notAfter);

        return $time === false ? null : (new DateTimeImmutable)->setTimestamp($time);
    }

    /** Whole days until expiry; negative once expired. */
    public function daysLeft(?int $now = null): ?int
    {
        $expires = $this->expiresAt();

        return $expires === null ? null : (int) floor(($expires->getTimestamp() - ($now ?? time())) / 86400);
    }

    public function __debugInfo(): array
    {
        return ['subject' => $this->subject, 'notAfter' => $this->notAfter, 'key' => '******'];
    }
}
