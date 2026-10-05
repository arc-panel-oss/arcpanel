<?php

namespace App\Services\WebDomains;

/**
 * One web domain as `v-list-web-domain(s) USER [DOMAIN] json` returns it.
 */
final class WebDomainRecord
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $proxyExtensions
     * @param  list<array{user: string, path: string}>  $ftpAccounts
     */
    public function __construct(
        public readonly string $user,
        public readonly string $domain,
        public readonly string $ip,
        public readonly array $aliases,
        public readonly string $documentRoot,
        public readonly string $template,
        public readonly string $backendTemplate,
        public readonly string $proxyTemplate,
        public readonly array $proxyExtensions,
        public readonly bool $ssl,
        public readonly bool $sslForce,
        public readonly bool $sslHsts,
        public readonly string $sslHome,
        public readonly string $sslKeyAlgo,
        public readonly bool $letsEncrypt,
        public readonly string $stats,
        public readonly string $statsUser,
        public readonly array $ftpAccounts,
        public readonly bool $fastcgiCache,
        public readonly string $fastcgiDuration,
        public readonly string $redirect,
        public readonly int $redirectCode,
        public readonly string $customDocroot,
        public readonly bool $suspended,
        public readonly int $diskMb,
        public readonly int $bandwidthMb,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $domain, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));
        $list = fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', $value($key))), fn ($v) => $v !== ''));

        $ftpUsers = explode(':', $value('FTP_USER'));
        $ftpPaths = explode(':', $value('FTP_PATH'));
        $ftp = [];
        foreach ($ftpUsers as $index => $ftpUser) {
            if ($ftpUser !== '') {
                $ftp[] = ['user' => $ftpUser, 'path' => $ftpPaths[$index] ?? ''];
            }
        }

        $keyAlgo = strtolower($value('SSL_KEY_ALGO'));
        if (! in_array($keyAlgo, WebDomainService::KEY_ALGORITHMS, true)) {
            // web/edit/web: domains with a certificate predate ECDSA keys
            $keyAlgo = $value('SSL') === 'yes' ? 'rsa' : 'ecdsa-256';
        }

        return new self(
            user: $user,
            domain: $domain,
            ip: $value('IP'),
            aliases: $list('ALIAS'),
            documentRoot: $value('DOCUMENT_ROOT'),
            template: $value('TPL'),
            backendTemplate: $value('BACKEND'),
            proxyTemplate: $value('PROXY'),
            proxyExtensions: $list('PROXY_EXT'),
            ssl: $value('SSL') === 'yes',
            sslForce: $value('SSL_FORCE') === 'yes',
            sslHsts: $value('SSL_HSTS') === 'yes',
            sslHome: $value('SSL_HOME') !== '' ? $value('SSL_HOME') : 'same',
            sslKeyAlgo: $keyAlgo,
            letsEncrypt: $value('LETSENCRYPT') === 'yes',
            stats: $value('STATS'),
            statsUser: $value('STATS_USER'),
            ftpAccounts: $ftp,
            fastcgiCache: $value('FASTCGI_CACHE') === 'yes' || $value('FASTCGI_DURATION') !== '',
            fastcgiDuration: $value('FASTCGI_DURATION'),
            redirect: $value('REDIRECT'),
            redirectCode: $value('REDIRECT_CODE') !== '' ? (int) $value('REDIRECT_CODE') : 302,
            customDocroot: $value('CUSTOM_DOCROOT'),
            suspended: $value('SUSPENDED') === 'yes',
            diskMb: (int) $value('U_DISK'),
            bandwidthMb: (int) $value('U_BANDWIDTH'),
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }

    /** Aliases other than www.DOMAIN, as the legacy list shows them. */
    public function extraAliases(): array
    {
        return array_values(array_filter($this->aliases, fn (string $alias) => $alias !== 'www.'.$this->domain));
    }

    public function hasProxy(): bool
    {
        return $this->proxyTemplate !== '';
    }

    /**
     * Custom document root split into the target domain and folder, as
     * web/edit/web reads /home/USER/web/TARGET/public_html/FOLDER.
     *
     * @return array{domain: string, folder: string}|null
     */
    public function customDocrootParts(): ?array
    {
        if ($this->customDocroot === '') {
            return null;
        }
        $prefix = '/home/'.$this->user.'/web/';
        if (preg_match('#^'.preg_quote($prefix, '#').'([[:alnum:]][^/]*)/public_html/?(.*)$#', $this->customDocroot, $m) !== 1) {
            return null;
        }

        return ['domain' => $m[1], 'folder' => trim($m[2], '/')];
    }
}
