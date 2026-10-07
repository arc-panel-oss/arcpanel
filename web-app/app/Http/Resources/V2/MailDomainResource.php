<?php

namespace App\Http\Resources\V2;

use App\Services\MailDomains\MailDomainRecord;
use App\Services\MailDomains\MailDomainSsl;
use App\Services\MailDomains\MailService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mail domain (`v-list-mail-domain USER DOMAIN json`). `ssl.certificate`
 * is only part of a single domain.
 *
 * @property MailDomainRecord $resource
 */
final class MailDomainResource extends JsonResource
{
    private ?MailDomainSsl $certificate = null;

    private bool $single = false;

    /** One domain with its certificate when SSL is on. */
    public static function single(MailService $mail, string $user, string $domain): self
    {
        $record = $mail->find($user, $domain);
        $resource = new self($record);
        $resource->single = true;
        $resource->certificate = $record->ssl ? $mail->ssl($user, $domain) : null;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $domain = $this->resource;

        return [
            /** @var string */
            'domain' => $domain->domain,
            /** @var string the owner */
            'user' => $domain->user,
            /** @var bool */
            'antispam' => $domain->antispam,
            /** @var bool */
            'antivirus' => $domain->antivirus,
            /** @var bool */
            'dkim' => $domain->dkim,
            /** @var bool */
            'reject' => $domain->reject,
            /** @var string|null the address that receives mail for unknown accounts */
            'catchall' => $domain->catchall !== '' ? $domain->catchall : null,
            /** @var string|null the webmail client, null when webmail is off */
            'webmail' => $domain->webmail !== '' ? $domain->webmail : null,
            /** @var int|null messages per hour, null for the system default */
            'rate_limit' => ctype_digit($domain->rateLimit) ? (int) $domain->rateLimit : null,
            'ssl' => [
                /** @var bool */
                'enabled' => $domain->ssl,
                /** @var bool the certificate comes from Let's Encrypt */
                'letsencrypt' => $domain->letsEncrypt,
                /** @var 'ecdsa-256'|'ecdsa-384'|'rsa'|null the stored key type */
                'key_algorithm' => $domain->storedKeyAlgo !== '' ? $domain->storedKeyAlgo : null,
                /** The installed certificate, null without SSL; only part of a single domain. */
                'certificate' => $this->when($this->single, fn () => $this->certificate !== null ? [
                    /** @var string */
                    'subject' => $this->certificate->subject,
                    /** @var list<string> the names it covers */
                    'names' => array_values(array_filter(preg_split('/[\s,]+/', $this->certificate->aliases) ?: [])),
                    /** @var string */
                    'issuer' => $this->certificate->issuer,
                    /** @var string|null start of validity, UTC, YYYY-MM-DDTHH:MM:SSZ */
                    'not_before' => self::utc($this->certificate->notBefore),
                    /** @var string|null end of validity, UTC, YYYY-MM-DDTHH:MM:SSZ */
                    'not_after' => self::utc($this->certificate->notAfter),
                ] : null),
            ],
            /** The SMTP relay, null when the domain sends mail itself. The password is never returned. */
            'smtp_relay' => $domain->relay && $domain->relayHost !== '' ? [
                /** @var string */
                'host' => $domain->relayHost,
                /** @var int|null */
                'port' => ctype_digit($domain->relayPort) ? (int) $domain->relayPort : null,
                /** @var string */
                'username' => $domain->relayUser,
            ] : null,
            /** @var int */
            'accounts' => $domain->accounts,
            /** @var int */
            'disk_mb' => $domain->diskMb,
            /** @var bool */
            'suspended' => $domain->suspended,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($domain->date, $domain->time),
        ];
    }

    /** A date of openssl (Dec 19 00:00:00 2026 GMT) as ISO 8601 UTC. */
    private static function utc(string $date): ?string
    {
        $time = $date !== '' ? strtotime($date) : false;

        return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
