<?php

namespace App\Http\Resources\V2;

use App\Services\WebDomains\WebDomainRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A web domain (`v-list-web-domain USER DOMAIN json`). `backend_port` is
 * only part of a single domain (getWebDomain and the write operations).
 *
 * @property WebDomainRecord $resource
 */
final class WebDomainResource extends JsonResource
{
    /** The app port of the domain's template (E6), when it was read. */
    private ?string $backendPort = null;

    private bool $withPort = false;

    /** Add the app port of the template, '' when the template takes none. */
    public function withBackendPort(string $port): self
    {
        $this->backendPort = $port;
        $this->withPort = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $domain = $this->resource;
        $docroot = $domain->customDocrootParts();

        return [
            /** @var string */
            'domain' => $domain->domain,
            /** @var string the owner */
            'user' => $domain->user,
            /** @var string the server address the domain is bound to (without its NAT address) */
            'ip' => $domain->ip,
            /** @var list<string> */
            'aliases' => $domain->aliases,
            /** @var string the folder the domain serves */
            'document_root' => $domain->customDocroot !== '' ? $domain->customDocroot : $domain->documentRoot,
            /** The folder of another domain of the same user that this domain serves; null for its own public_html. */
            'custom_document_root' => $docroot !== null ? [
                /** @var string */
                'domain' => $docroot['domain'],
                /** @var string a folder below its public_html, '' for public_html itself */
                'folder' => $docroot['folder'],
            ] : null,
            'templates' => [
                /** @var string */
                'web' => $domain->template,
                /** @var string|null null without a PHP-FPM backend */
                'backend' => $domain->backendTemplate !== '' ? $domain->backendTemplate : null,
                /** @var string|null null with the proxy off */
                'proxy' => $domain->hasProxy() ? $domain->proxyTemplate : null,
            ],
            /** @var list<string> extensions the proxy serves itself */
            'proxy_extensions' => $domain->proxyExtensions,
            'fastcgi_cache' => [
                /** @var bool */
                'enabled' => $domain->fastcgiCache,
                /** @var string|null how long NGINX keeps a page, such as 2m */
                'duration' => $domain->fastcgiDuration !== '' ? $domain->fastcgiDuration : null,
            ],
            'ssl' => [
                /** @var bool */
                'enabled' => $domain->ssl,
                /** @var bool the certificate comes from Let's Encrypt */
                'letsencrypt' => $domain->letsEncrypt,
                /** @var bool HTTP is redirected to HTTPS */
                'force' => $domain->sslForce,
                /** @var bool */
                'hsts' => $domain->sslHsts,
                /** @var 'same'|'single' public_html for HTTP and HTTPS, or private/ for HTTPS */
                'home' => $domain->sslHome === 'single' ? 'single' : 'same',
                /** @var 'ecdsa-256'|'ecdsa-384'|'rsa' the key type of a Let's Encrypt certificate */
                'key_algorithm' => $domain->sslKeyAlgo,
            ],
            /** Where every request is redirected; null without a redirect. */
            'redirect' => $domain->redirect !== '' ? [
                /** @var string */
                'url' => $domain->redirect,
                /** @var int */
                'code' => $domain->redirectCode,
            ] : null,
            /** @var string|null the statistics engine, null without statistics */
            'stats' => $domain->stats !== '' && $domain->stats !== 'none' ? $domain->stats : null,
            /** @var list<array{username: string, path: string}> */
            'ftp_accounts' => array_map(fn (array $account) => ['username' => $account['user'], 'path' => $account['path']], $domain->ftpAccounts),
            /** @var bool */
            'suspended' => $domain->suspended,
            /** @var int */
            'disk_mb' => $domain->diskMb,
            /** @var int */
            'bandwidth_mb' => $domain->bandwidthMb,
            /** @var int|null the app port of the domain's template (E6); null when the template takes none */
            'backend_port' => $this->when($this->withPort, fn () => ctype_digit((string) $this->backendPort) ? (int) $this->backendPort : null),
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($domain->date, $domain->time),
        ];
    }
}
