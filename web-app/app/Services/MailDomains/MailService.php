<?php

namespace App\Services\MailDomains;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\Request;

/**
 * Mail domains and accounts through the v-* commands, with the arguments
 * and order of the legacy pages (web/list/mail, web/add/mail,
 * web/edit/mail, web/bulk/mail, web/{suspend,unsuspend,delete}/mail).
 */
final class MailService
{
    public const BULK_COMMANDS = [
        'suspend' => 'v-suspend-mail-domain',
        'unsuspend' => 'v-unsuspend-mail-domain',
        'rebuild' => 'v-rebuild-mail-domain',
        'delete' => 'v-delete-mail-domain',
    ];

    public const ACCOUNT_BULK_COMMANDS = [
        'suspend' => 'v-suspend-mail-account',
        'unsuspend' => 'v-unsuspend-mail-account',
        'delete' => 'v-delete-mail-account',
    ];

    /** Webmail alias when the server sets none (templates/pages/list_mail.php). */
    public const DEFAULT_WEBMAIL_ALIAS = 'webmail';

    /** @var array<string, array<string, MailDomainRecord>> per-request cache */
    private array $lists = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
        private readonly PlanRunner $plans,
        private readonly Request $request,
    ) {}

    /** @return array<string, MailDomainRecord> domain => record */
    public function forUser(string $user): array
    {
        if (! isset($this->lists[$user])) {
            $records = [];
            foreach ($this->arc->json('v-list-mail-domains', [$user]) as $domain => $row) {
                if (is_string($domain) && is_array($row)) {
                    $records[$domain] = MailDomainRecord::fromArray($user, $domain, $row);
                }
            }
            $this->lists[$user] = $records;
        }

        return $this->lists[$user];
    }

    /**
     * @param  list<string>  $users
     * @return list<MailDomainRecord> sorted by domain, then owner
     */
    public function forUsers(array $users): array
    {
        $records = [];
        foreach ($users as $user) {
            array_push($records, ...array_values($this->forUser($user)));
        }
        usort($records, fn (MailDomainRecord $a, MailDomainRecord $b) => [$a->domain, $a->user] <=> [$b->domain, $b->user]);

        return $records;
    }

    /** One mail domain; 404 when the user has no such domain. */
    public function find(string $user, string $domain): MailDomainRecord
    {
        return MailDomainRecord::fromArray($user, $domain, $this->lookup->one('v-list-mail-domain', [$user, $domain], $domain));
    }

    /** @return list<MailAccountRecord> the accounts of a domain by name; 404 when the domain is missing */
    public function accounts(string $user, string $domain): array
    {
        $accounts = [];
        foreach ($this->lookup->decode('v-list-mail-accounts', [$user, $domain]) as $account => $row) {
            if (is_array($row)) {
                $accounts[] = MailAccountRecord::fromArray($user, $domain, (string) $account, $row);
            }
        }
        usort($accounts, fn (MailAccountRecord $a, MailAccountRecord $b) => strcmp($a->account, $b->account));

        return $accounts;
    }

    /** One account; 404 when the domain has no such account. */
    public function findAccount(string $user, string $domain, string $account): MailAccountRecord
    {
        return MailAccountRecord::fromArray($user, $domain, $account, $this->lookup->one('v-list-mail-account', [$user, $domain, $account], $account));
    }

    /** The autoreply message of an account with real line breaks, '' when there is none. */
    public function autoreplyMessage(MailAccountRecord $account): string
    {
        if (! $account->autoreply) {
            return '';
        }
        $data = $this->arc->json('v-list-mail-account-autoreply', [$account->user, $account->domain, $account->account], Options::allowFailure());
        $message = $data[$account->account]['MSG'] ?? '';

        return is_string($message) ? str_replace('\n', "\n", $message) : '';
    }

    /** The certificate of a domain with SSL. */
    public function ssl(string $user, string $domain): MailDomainSsl
    {
        return MailDomainSsl::fromArray($this->lookup->one('v-list-mail-domain-ssl', [$user, $domain], $domain));
    }

    /** @return list<string> webmail clients of the server (`v-list-sys-webmail`) */
    public function webmailClients(): array
    {
        return array_values(array_filter(
            array_map(fn ($name) => is_string($name) ? trim($name) : '', $this->arc->json('v-list-sys-webmail', [], Options::allowFailure())),
            fn (string $name) => $name !== '',
        ));
    }

    /**
     * The optional parts of the forms: spam filter and reject spam
     * (ANTISPAM_SYSTEM), anti-virus (ANTIVIRUS_SYSTEM), webmail client
     * (IMAP_SYSTEM and WEBMAIL_SYSTEM).
     *
     * @return array{antispam: bool, antivirus: bool, webmail: bool}
     */
    public function systems(): array
    {
        return [
            'antispam' => $this->config->get('ANTISPAM_SYSTEM') !== '',
            'antivirus' => $this->config->get('ANTIVIRUS_SYSTEM') !== '',
            'webmail' => $this->config->get('IMAP_SYSTEM') !== '' && $this->config->get('WEBMAIL_SYSTEM') !== '',
        ];
    }

    /** Whether the server runs a webmail (WEBMAIL_SYSTEM). */
    public function hasWebmail(): bool
    {
        return $this->config->get('WEBMAIL_SYSTEM') !== '';
    }

    /** WEBMAIL_ALIAS of the server, "webmail" by default. */
    public function webmailAlias(): string
    {
        return $this->config->get('WEBMAIL_ALIAS') ?: self::DEFAULT_WEBMAIL_ALIAS;
    }

    /** "https://webmail.example.com/" of a domain, http:// without SSL, also when its webmail is off (credentials mail). */
    public function webmailUrl(MailDomainRecord $domain): string
    {
        return $domain->webmailDomainUrl($this->webmailAlias());
    }

    /**
     * "https://<panel host>:8086/" while "Open Webmail" uses the webmail port
     * (WEBMAIL_PORT set, WEBMAIL_LINK not "domain"; E46), else null.
     */
    public function webmailPortUrl(): ?string
    {
        $port = $this->config->get('WEBMAIL_PORT');
        if ($port === '' || $this->config->get('WEBMAIL_LINK') === 'domain') {
            return null;
        }

        return 'https://'.$this->request->getHost().':'.$port.'/';
    }

    /** The "Open Webmail" link of a domain, null when the server or the domain has no webmail. */
    public function openWebmailUrl(MailDomainRecord $domain): ?string
    {
        return $this->hasWebmail() ? $domain->webmailUrl($this->webmailAlias(), $this->webmailPortUrl()) : null;
    }

    /** The webmail of the client settings panel: the port link, else the domain link. */
    public function clientWebmailUrl(MailDomainRecord $domain): ?string
    {
        return $this->hasWebmail() ? ($this->webmailPortUrl() ?? $this->webmailUrl($domain)) : null;
    }

    /**
     * The DNS records a mail domain needs (templates/pages/list_mail_dns.php):
     * A for mail. and the webmail alias, MX, SPF, DMARC and the DKIM keys.
     * The address is the NAT address of the user's first IP, else the IP.
     *
     * @return list<array{record: string, type: string, priority: string, ttl: string, value: string}>
     */
    public function dnsHints(MailDomainRecord $domain): array
    {
        $ip = '';
        foreach ($this->arc->json('v-list-user-ips', [$domain->user], Options::allowFailure()) as $address => $row) {
            $nat = is_array($row) ? trim((string) ($row['NAT'] ?? '')) : '';
            $ip = $nat !== '' ? $nat : (string) $address;
            break;
        }

        $name = $domain->domain;
        $row = fn (string $record, string $type, string $ttl, string $value, string $priority = '') => compact('record', 'type', 'priority', 'ttl', 'value');
        $rows = [$row('mail.'.$name, 'A', '14400', $ip)];
        if ($this->hasWebmail()) {
            $rows[] = $row($this->webmailAlias().'.'.$name, 'A', '14400', $ip);
        }
        $rows[] = $row($name, 'MX', '14400', 'mail.'.$name.'.', '10');
        $rows[] = $row($name, 'TXT', '14400', 'v=spf1 a mx ip4:'.$ip.' -all');
        $rows[] = $row('_dmarc', 'TXT', '14400', 'v=DMARC1; p=quarantine; pct=100');

        foreach ($this->arc->json('v-list-mail-domain-dkim-dns', [$domain->user, $name], Options::allowFailure()) as $record => $dkim) {
            if (is_string($record) && is_array($dkim)) {
                $rows[] = $row($record, 'TXT', '3600', str_replace(['"', "'"], '', (string) ($dkim['TXT'] ?? '')));
            }
        }

        return $rows;
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $this->lists = [];

        return $this->plans->apply($steps);
    }

    /**
     * web/bulk/mail: each domain; the mail commands restart the services
     * themselves.
     *
     * @param  list<array{0: string, 1: string}>  $items  [owner, domain]
     */
    public function bulk(string $action, array $items): BulkResult
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $this->lists = [];

        return $this->runner->run($items, fn (array $item) => [$item[1], $command, [$item[0], $item[1]]]);
    }

    /** web/{suspend,unsuspend,delete}/mail?domain=: one domain. */
    public function single(string $action, string $user, string $domain): BulkResult
    {
        return $this->bulk($action, [[$user, $domain]]);
    }

    /**
     * web/bulk/mail?account=: each account of one domain.
     *
     * @param  list<string>  $accounts
     */
    public function bulkAccounts(string $action, string $user, string $domain, array $accounts): BulkResult
    {
        $command = self::ACCOUNT_BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");

        return $this->runner->run($accounts, fn (string $account) => [$account, $command, [$user, $domain, $account]]);
    }
}
