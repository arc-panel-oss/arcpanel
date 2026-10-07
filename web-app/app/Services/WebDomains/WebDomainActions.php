<?php

namespace App\Services\WebDomains;

use App\Services\Ports\PortService;
use App\Services\UserDirectory;
use App\Services\WebDomains\Tabs\AdvancedTab;
use App\Services\WebDomains\Tabs\GeneralTab;
use App\Services\WebDomains\Tabs\TemplateVarsTab;
use Illuminate\Validation\ValidationException;

/**
 * Choice checks and plans of web domains, shared by the panel pages
 * (WebDomainController, WebDomainEditController) and API v2 (E34 spec D2).
 * Validation errors name the fields of the panel forms (ip, template,
 * backend_template, proxy_template, backend_port, docroot_domain).
 */
final class WebDomainActions
{
    public function __construct(
        private readonly WebDomainService $web,
        private readonly TemplateCatalog $catalog,
        private readonly PortService $ports,
        private readonly UserDirectory $directory,
        private readonly AdvancedTab $advanced,
        private readonly TemplateVarsTab $vars,
        private readonly GeneralTab $general,
    ) {}

    /**
     * The IP addresses of $user, refusing $ip when it is not one of them.
     *
     * @return array<string, array<string, string>> `v-list-user-ips USER json`
     *
     * @throws ValidationException
     */
    public function checkIp(string $user, string $ip): array
    {
        $userIps = $this->web->userIps($user);
        if (! array_key_exists($ip, $userIps)) {
            throw ValidationException::withMessages(['ip' => __('Please select a valid IP address.')]);
        }

        return $userIps;
    }

    /**
     * Template names must be templates of the server.
     *
     * @param  array{template?: ?string, backend_template?: ?string, proxy_template?: ?string}  $values
     *
     * @throws ValidationException
     */
    public function checkTemplates(array $values): void
    {
        $known = [
            'template' => fn () => $this->catalog->names('web'),
            'backend_template' => fn () => $this->web->backendTemplates(),
            'proxy_template' => fn () => $this->catalog->names('proxy'),
        ];
        foreach ($known as $field => $names) {
            $value = $values[$field] ?? null;
            if ($value !== null && $value !== '' && ! in_array($value, $names(), true)) {
                throw ValidationException::withMessages([$field => __('Please select a valid template.')]);
            }
        }
    }

    /**
     * Custom document roots point to another domain of the same user.
     *
     * @param  array{docroot_custom?: bool, docroot_domain?: ?string}  $values
     *
     * @throws ValidationException
     */
    public function checkDocroot(string $user, array $values): void
    {
        if (($values['docroot_custom'] ?? false) && ! in_array($values['docroot_domain'] ?? null, array_keys($this->web->forUser($user)), true)) {
            throw ValidationException::withMessages(['docroot_domain' => __('Please select one of your domains.')]);
        }
    }

    /**
     * The backend port asked for a domain whose visitor-facing template takes
     * one must be "auto" or a port of the owner's pool (E6 spec §7.2).
     * $record is null for a domain that does not exist yet.
     *
     * @param  array{template?: ?string, proxy?: bool, proxy_template?: ?string}  $values
     *
     * @throws ValidationException
     */
    public function checkPort(string $user, string $domain, ?WebDomainRecord $record, array $values, string $port): void
    {
        $kind = $this->catalog->activeKind();
        if ($kind === null || $port === '') {
            return;
        }
        if ($record !== null) {
            $meta = $this->activeMeta($kind, $record, $values);
            $current = ($meta?->portVariable() !== null) ? ($this->web->templateVars($user, $domain)['variables'][$meta->portVariable()] ?? '') : '';
        } else {
            $field = $kind === 'web' ? 'template' : 'proxy_template';
            $meta = $this->catalog->find($kind, trim((string) ($values[$field] ?? '')) ?: 'default');
            $current = '';
        }
        if ($meta?->portVariable() !== null && ! BackendPorts::accepts($this->ports->account($user), $domain, $current, $port)) {
            throw ValidationException::withMessages(['backend_port' => __('Please select one of the ports of the list.')]);
        }
    }

    /**
     * web/add/web: the domain (with its aliases, which replace the default
     * www alias), then the DNS zone and mail domain when asked.
     *
     * @param  list<string>  $aliases
     * @param  array<string, array<string, string>>  $userIps
     * @return list<Step>
     */
    public function planCreate(string $owner, string $domain, string $ip, bool $dns, bool $mail, array $aliases, array $userIps): array
    {
        $steps = $this->web->planCreate($owner, $domain, $ip, $dns, $mail, $userIps);
        if ($aliases !== []) {
            $steps[0] = new Step($steps[0]->label, 'v-add-web-domain', [$owner, $domain, $ip, 'yes', implode(',', $aliases)]);
        }

        return $steps;
    }

    /**
     * Template steps of a domain that was just created (web/add/web): the
     * asked templates over those the domain got from its owner's package,
     * the proxy and cache as they are.
     *
     * @param  array{template?: ?string, backend_template?: ?string, proxy_template?: ?string, proxy_extensions?: ?string}  $values
     * @return list<Step>
     */
    public function planCreatedTemplates(WebDomainRecord $record, array $values, string $port): array
    {
        return $this->planTemplates($record, [
            'template' => $values['template'] ?? null,
            'backend_template' => $values['backend_template'] ?? null,
            'proxy' => $record->hasProxy(),
            'proxy_template' => $values['proxy_template'] ?? null,
            'proxy_extensions' => ($values['proxy_extensions'] ?? '') !== '' ? $values['proxy_extensions'] : implode(',', $record->proxyExtensions),
            'fastcgi_cache' => $record->fastcgiCache,
            'fastcgi_duration' => $record->fastcgiDuration,
        ], $port, created: true);
    }

    /**
     * The Advanced tab: templates, proxy and cache, then the backend port of
     * the visitor-facing template (E6 spec §7.2).
     *
     * @param  array<string, mixed>  $values  the fields of AdvancedTab::plan()
     * @return list<Step>
     */
    public function planTemplates(WebDomainRecord $record, array $values, string $port, bool $created = false): array
    {
        $steps = $this->advanced->plan($record, $values, $this->web->systems());

        $kind = $this->catalog->activeKind();
        $meta = $kind !== null ? $this->activeMeta($kind, $record, $values) : null;
        $vars = $kind !== null && ! $created ? $this->web->templateVars($record->user, $record->domain)['variables'] : [];
        $changed = $created || TemplateVarsTab::activeTemplate($kind, $record, $values) !== TemplateVarsTab::currentTemplate($kind, $record);

        return [...$steps, ...$this->vars->plan($record, $meta, $port, $vars, $changed)];
    }

    /**
     * The General tab: IP address, aliases and custom document root.
     *
     * @param  array{aliases?: ?string, ip?: ?string, docroot_custom?: bool, docroot_domain?: ?string, docroot_folder?: ?string}  $values
     * @param  array<string, array<string, string>>  $userIps
     * @return list<Step>
     */
    public function planGeneral(WebDomainRecord $record, array $values, array $userIps): array
    {
        return $this->general->plan($record, $values, $userIps, $this->web);
    }

    /**
     * The record a new domain will have, for a dry run: the templates of its
     * owner's package, the proxy when the server has one (as v-add-web-domain
     * sets them).
     *
     * @param  list<string>  $aliases
     */
    public function predictRecord(string $owner, string $domain, string $ip, array $aliases): WebDomainRecord
    {
        $user = $this->directory->all()[$owner] ?? [];
        $systems = $this->web->systems();

        return WebDomainRecord::fromArray($owner, $domain, [
            'IP' => $ip,
            'ALIAS' => implode(',', $aliases !== [] ? $aliases : ['www.'.$domain]),
            'TPL' => ($user['WEB_TEMPLATE'] ?? '') ?: 'default',
            'BACKEND' => $systems['backend'] ? (($user['BACKEND_TEMPLATE'] ?? '') ?: 'default') : '',
            'PROXY' => $systems['proxy'] ? (($user['PROXY_TEMPLATE'] ?? '') ?: 'default') : '',
            'DOCUMENT_ROOT' => "/home/{$owner}/web/{$domain}/public_html/",
        ]);
    }

    /** The owner exists; 404 otherwise (an administrator may name any user). */
    public function ownerExists(string $owner): bool
    {
        return array_key_exists($owner, $this->directory->all());
    }

    /** @param  array<string, mixed>  $values */
    private function activeMeta(string $kind, WebDomainRecord $record, array $values): ?TemplateMeta
    {
        $active = TemplateVarsTab::activeTemplate($kind, $record, $values);

        return $active !== null ? $this->catalog->find($kind, $active) : null;
    }
}
