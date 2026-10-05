<?php

namespace App\Services\WebDomains;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Arc\SysConfig;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Web domains through the v-* commands, one method per command, with the
 * arguments and order of the legacy pages (web/list/web, web/add/web,
 * web/edit/web, web/bulk/web, web/list/web-log).
 */
final class WebDomainService implements DomainProbe
{
    public const KEY_ALGORITHMS = ['ecdsa-256', 'ecdsa-384', 'rsa'];

    /** @var array<string, array<string, WebDomainRecord>> per-request cache */
    private array $lists = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    /** @return array<string, WebDomainRecord> domain => record */
    public function forUser(string $user): array
    {
        if (! isset($this->lists[$user])) {
            $records = [];
            foreach ($this->arc->json('v-list-web-domains', [$user]) as $domain => $row) {
                if (is_string($domain) && is_array($row)) {
                    $records[$domain] = WebDomainRecord::fromArray($user, $domain, $row);
                }
            }
            $this->lists[$user] = $records;
        }

        return $this->lists[$user];
    }

    /**
     * Web domains of several users, merged and sorted by domain.
     *
     * @param  list<string>  $users
     * @return list<WebDomainRecord>
     */
    public function forUsers(array $users): array
    {
        $records = [];
        foreach ($users as $user) {
            foreach ($this->forUser($user) as $record) {
                $records[] = $record;
            }
        }
        usort($records, fn (WebDomainRecord $a, WebDomainRecord $b) => [$a->domain, $a->user] <=> [$b->domain, $b->user]);

        return $records;
    }

    /** One domain; 404 when the user has no such domain. */
    public function find(string $user, string $domain): WebDomainRecord
    {
        $result = $this->arc->run('v-list-web-domain', [$user, $domain, 'json'], Options::allowFailure());
        if ($result->exit() === ExitCode::NotExist || $result->exit() === ExitCode::Args || $result->exit() === ExitCode::Invalid) {
            throw new NotFoundHttpException;
        }
        if (! $result->ok()) {
            throw ArcCommandException::failed($result);
        }
        $data = json_decode($result->stdout, true);
        if (! is_array($data) || ! is_array($data[$domain] ?? null)) {
            throw new NotFoundHttpException;
        }

        return WebDomainRecord::fromArray($user, $domain, $data[$domain]);
    }

    public function ssl(string $user, string $domain): ?WebDomainSsl
    {
        $data = $this->arc->json('v-list-web-domain-ssl', [$user, $domain], Options::allowFailure());

        return is_array($data[$domain] ?? null) ? WebDomainSsl::fromArray($data[$domain]) : null;
    }

    /** @return array<string, array<string, string>> `v-list-user-ips USER json` */
    public function userIps(string $user): array
    {
        return $this->arc->json('v-list-user-ips', [$user]);
    }

    /** @return array<string, array<string, string>> `v-list-sys-ips json` */
    public function systemIps(): array
    {
        return $this->arc->json('v-list-sys-ips', [], Options::allowFailure());
    }

    /** The address users see: the NAT address when the IP has one (web/list/web). */
    public function publicIp(string $ip, array $ips): string
    {
        $nat = trim((string) ($ips[$ip]['NAT'] ?? ''));

        return $nat !== '' ? $nat : $ip;
    }

    /** @return list<string> */
    public function webTemplates(): array
    {
        return $this->stringList($this->arc->json('v-list-web-templates', [], Options::allowFailure()));
    }

    /** @return list<string> */
    public function backendTemplates(): array
    {
        return $this->config->get('WEB_BACKEND') === ''
            ? []
            : $this->stringList($this->arc->json('v-list-web-templates-backend', [], Options::allowFailure()));
    }

    /** @return list<string> */
    public function proxyTemplates(): array
    {
        return $this->config->get('PROXY_SYSTEM') === ''
            ? []
            : $this->stringList($this->arc->json('v-list-web-templates-proxy', [], Options::allowFailure()));
    }

    /** @return list<string> statistics engines, "none" first */
    public function statsEngines(): array
    {
        return $this->stringList($this->arc->json('v-list-web-stats', [], Options::allowFailure()));
    }

    /** @return array{backend: bool, proxy: bool, nginx: bool, ftp: bool} */
    public function systems(): array
    {
        return [
            'backend' => $this->config->get('WEB_BACKEND') !== '',
            'proxy' => $this->config->get('PROXY_SYSTEM') !== '',
            'nginx' => $this->config->get('WEB_SYSTEM') === 'nginx',
            'ftp' => $this->config->get('FTP_SYSTEM') !== '',
            'dns' => $this->config->get('DNS_SYSTEM') !== '',
            'mail' => $this->config->get('MAIL_SYSTEM') !== '',
        ];
    }

    /** Whether nginx serves the domains and can purge their cache. */
    public function canPurgeCache(): bool
    {
        return $this->config->get('WEB_SYSTEM') === 'nginx' || $this->config->get('PROXY_SYSTEM') === 'nginx';
    }

    public function dnsDomainExists(string $user, string $domain): bool
    {
        return $this->config->get('DNS_SYSTEM') !== ''
            && $this->arc->run('v-list-dns-domain', [$user, $domain, 'json'], Options::allowFailure())->ok();
    }

    public function mailDomainExists(string $user, string $domain): bool
    {
        return $this->config->get('MAIL_SYSTEM') !== ''
            && $this->arc->run('v-list-mail-domain', [$user, $domain, 'json'], Options::allowFailure())->ok();
    }

    /**
     * web/add/web: the domain, then the DNS zone and mail domain when asked
     * (DNS with the public address of the IP).
     *
     * @return list<Step>
     */
    public function planCreate(string $user, string $domain, string $ip, bool $dns, bool $mail, array $userIps): array
    {
        $steps = [new Step(sprintf(__('Add web domain %s'), $domain), 'v-add-web-domain', [$user, $domain, $ip, 'yes'])];
        if ($dns) {
            $steps[] = new Step(__('Add the DNS zone'), 'v-add-dns-domain', [$user, $domain, $this->publicIp($ip, $userIps), '', '', '', '', '', '', '', '', 'no']);
        }
        if ($mail) {
            $steps[] = new Step(__('Add the mail domain'), 'v-add-mail-domain', [$user, $domain]);
        }

        return $steps;
    }

    /**
     * Run the steps in order. The first failure stops the run; steps before
     * it stay applied. Services are restarted once at the end, only when
     * every step succeeded, as web/edit/web does.
     *
     * @param  list<Step>  $steps
     */
    public function apply(array $steps): ApplyResult
    {
        $applied = [];
        $restart = [];
        foreach ($steps as $step) {
            try {
                $this->arc->run($step->command, $step->args);
            } catch (ArcCommandException $e) {
                return new ApplyResult($applied, $step, $e->userMessage());
            }
            $applied[] = $step;
            foreach ($step->restart as $service) {
                $restart[$service] = true;
            }
            if ($step->onSuccess !== null) {
                ($step->onSuccess)();
            }
        }

        $restarts = [
            'web' => [__('Restart the web server'), 'v-restart-web'],
            'proxy' => [__('Restart the proxy server'), 'v-restart-proxy'],
            'dns' => [__('Restart the DNS server'), 'v-restart-dns'],
        ];
        foreach ($restarts as $service => [$label, $command]) {
            if (! isset($restart[$service]) || ($service === 'proxy' && $this->config->get('PROXY_SYSTEM') === '')) {
                continue;
            }
            $step = new Step($label, $command, []);
            try {
                $this->arc->run($command);
            } catch (ArcCommandException $e) {
                return new ApplyResult($applied, $step, $e->userMessage());
            }
        }

        return new ApplyResult($applied);
    }

    /**
     * Suspend, unsuspend, rebuild, purge or delete domains (web/bulk/web):
     * each command with RESTART=no, then one round of restarts. Every item
     * runs even when an earlier one failed.
     *
     * @param  list<array{user: string, domain: string}>  $items
     * @return array{done: list<string>, failed: array<string, string>}
     */
    public function bulk(string $action, array $items): array
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $done = [];
        $failed = [];
        foreach ($items as $item) {
            $result = $this->arc->run($command, [$item['user'], $item['domain'], 'no'], Options::allowFailure());
            if ($result->ok()) {
                $done[] = $item['domain'];
            } else {
                $failed[$item['domain']] = $result->message() !== '' ? $result->message() : sprintf(__('Error code: %s'), $result->exitCode);
            }
        }

        if ($items !== []) {
            foreach (['v-restart-web', 'v-restart-proxy', 'v-restart-dns', 'v-restart-web-backend'] as $restart) {
                $this->arc->run($restart, [], Options::allowFailure());
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    public const BULK_COMMANDS = [
        'suspend' => 'v-suspend-web-domain',
        'unsuspend' => 'v-unsuspend-web-domain',
        'rebuild' => 'v-rebuild-web-domain',
        'purge' => 'v-purge-nginx-cache',
        'delete' => 'v-delete-web-domain',
    ];

    /** @return list<string> last lines of the access or error log */
    public function log(string $user, string $domain, string $type): array
    {
        $command = $type === 'error' ? 'v-list-web-domain-errorlog' : 'v-list-web-domain-accesslog';

        // Plain lines: the JSON format of these commands does not escape backslashes.
        return $this->arc->run($command, [$user, $domain])->lines();
    }

    /** @return list<string> */
    private function stringList(array $data): array
    {
        return array_values(array_filter($data, 'is_string'));
    }
}
