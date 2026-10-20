<?php

namespace App\Services\MailDomains;

/**
 * One mail domain as `v-list-mail-domain(s) USER [DOMAIN] json` returns it.
 * The list gives the bare webmail alias ("webmail"), the single domain the
 * host name ("webmail.example.com"); both are kept as the bare alias.
 */
final class MailDomainRecord
{
    /** Key algorithms of a Let's Encrypt certificate (web/edit/mail). */
    public const KEY_ALGORITHMS = ['ecdsa-256', 'ecdsa-384', 'rsa'];

    public function __construct(
        public readonly string $user,
        public readonly string $domain,
        public readonly bool $antivirus,
        public readonly bool $antispam,
        public readonly bool $reject,
        public readonly string $rateLimit,
        public readonly bool $dkim,
        public readonly string $catchall,
        public readonly int $accounts,
        public readonly int $diskMb,
        public readonly bool $ssl,
        /** SSL_KEY_ALGO as stored, lower-cased; '' when unknown */
        public readonly string $storedKeyAlgo,
        public readonly bool $letsEncrypt,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
        /** bare webmail alias of the server ("webmail"), '' when unknown */
        public readonly string $webmailAlias,
        /** webmail client ("roundcube"), '' when webmail is off */
        public readonly string $webmail,
        public readonly bool $relay,
        public readonly string $relayHost,
        public readonly string $relayPort,
        public readonly string $relayUser,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $domain, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));
        $alias = $value('WEBMAIL_ALIAS');
        if (str_ends_with($alias, '.'.$domain)) {
            $alias = substr($alias, 0, -strlen('.'.$domain));
        }

        return new self(
            user: $user,
            domain: $domain,
            antivirus: $value('ANTIVIRUS') === 'yes',
            antispam: $value('ANTISPAM') === 'yes',
            reject: $value('REJECT') === 'yes',
            rateLimit: $value('RATE_LIMIT'),
            dkim: $value('DKIM') === 'yes',
            catchall: $value('CATCHALL'),
            accounts: (int) $value('ACCOUNTS'),
            diskMb: (int) $value('U_DISK'),
            ssl: $value('SSL') === 'yes',
            storedKeyAlgo: strtolower($value('SSL_KEY_ALGO')),
            letsEncrypt: $value('LETSENCRYPT') === 'yes',
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
            webmailAlias: $alias,
            webmail: $value('WEBMAIL') === 'disabled' ? '' : $value('WEBMAIL'),
            relay: $value('U_SMTP_RELAY') === 'true',
            relayHost: $value('U_SMTP_RELAY_HOST'),
            relayPort: $value('U_SMTP_RELAY_PORT'),
            relayUser: $value('U_SMTP_RELAY_USERNAME'),
        );
    }

    /**
     * The key algorithm the SSL tab starts from (web/edit/mail): the stored
     * one, else "rsa" for an existing certificate and "ecdsa-256" otherwise.
     */
    public function keyAlgo(): string
    {
        if (in_array($this->storedKeyAlgo, self::KEY_ALGORITHMS, true)) {
            return $this->storedKeyAlgo;
        }

        return $this->ssl ? 'rsa' : 'ecdsa-256';
    }

    /**
     * The "Open Webmail" link when the domain has webmail, else null: the
     * webmail port of the panel host when PORTURL is given (E46), else
     * "https://webmail.example.com/", or http:// while the mail domain has no
     * SSL (the inherited pages did the same).
     */
    public function webmailUrl(string $fallbackAlias, ?string $portUrl = null): ?string
    {
        if ($this->webmail === '') {
            return null;
        }

        return $portUrl ?? $this->webmailDomainUrl($fallbackAlias);
    }

    /** "https://webmail.example.com/", http:// while the mail domain has no SSL, also when its webmail is off. */
    public function webmailDomainUrl(string $fallbackAlias): string
    {
        return ($this->ssl ? 'https' : 'http').'://'.($this->webmailAlias !== '' ? $this->webmailAlias : $fallbackAlias).'.'.$this->domain.'/';
    }
}
