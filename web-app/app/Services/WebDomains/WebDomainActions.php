<?php

namespace App\Services\WebDomains;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Php\PhpManager;
use App\Services\Ports\PortService;
use App\Services\UserDirectory;
use App\Services\WebDomains\Tabs\AdvancedTab;
use App\Services\WebDomains\Tabs\GeneralTab;
use App\Services\WebDomains\Tabs\SslTab;
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
        private readonly SslTab $ssl,
        private readonly PhpManager $php,
    ) {}

    /**
     * The backend templates a principal may choose for a domain of $user
     * (E41 §7.5): administrators every template; others a template that names
     * a PHP version only when the user's package allows that version, and
     * the domain's current template.
     *
     * @return list<string>
     */
    public function backendTemplatesFor(Principal $principal, string $user, string $current = ''): array
    {
        $all = $this->web->backendTemplates();
        if ($principal->loginRole === Role::Admin) {
            return $all;
        }
        $keys = $this->php->packageOf((string) ($this->directory->all()[$user]['PACKAGE'] ?? ''));

        return array_values(array_filter($all, fn (string $template) => $template === $current || $this->php->templateAllowed($template, $keys)));
    }

    /**
     * Refuse a backend template of a PHP version the package does not allow.
     *
     * @throws ValidationException
     */
    public function checkBackendTemplate(Principal $principal, string $user, ?string $template, string $current = ''): void
    {
        if ($template === null || $template === '' || $template === $current) {
            return;
        }
        if (! in_array($template, $this->backendTemplatesFor($principal, $user, $current), true)) {
            throw ValidationException::withMessages(['backend_template' => __('The package does not allow this PHP version.')]);
        }
    }

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
     * New aliases of a domain, with their DNS records and statistics as the
     * General tab changes them; a Let's Encrypt certificate is queued again
     * for the new aliases.
     *
     * @param  list<string>  $aliases
     * @return list<Step>
     */
    public function planAliases(WebDomainRecord $record, array $aliases): array
    {
        $parts = $record->customDocrootParts();
        $values = [
            'aliases' => implode(',', $aliases),
            'ip' => $record->ip,
            'docroot_custom' => $record->customDocroot !== '',
            'docroot_domain' => $parts['domain'] ?? null,
            'docroot_folder' => $parts['folder'] ?? null,
        ];

        return self::queueLetsEncrypt($this->planGeneral($record, $values, $this->web->userIps($record->user)));
    }

    /** Queue a Let's Encrypt certificate for the aliases of the domain (E34 spec D15). */
    public function planLetsEncrypt(WebDomainRecord $record, string $keyAlgorithm): Step
    {
        return new Step(__('Queue a Let’s Encrypt certificate'), 'v-schedule-letsencrypt-domain',
            [$record->user, $record->domain, implode(',', $record->aliases), $keyAlgorithm]);
    }

    /**
     * The SSL tab for an uploaded certificate, or for forced HTTPS and HSTS
     * alone when $values has no certificate. A Let's Encrypt certificate is
     * removed before an uploaded one is installed.
     *
     * @param  array{certificate?: ?string, key?: ?string, ca?: ?string, force?: bool, hsts?: bool}  $values
     * @return list<Step>
     */
    public function planSsl(WebDomainRecord $record, array $values): array
    {
        $uploaded = Input::pem($values['certificate'] ?? '') !== '';
        $input = [
            'ssl' => true,
            'letsencrypt' => ! $uploaded && $record->letsEncrypt,
            'key_algo' => $record->sslKeyAlgo,
            'certificate' => $values['certificate'] ?? null,
            'key' => $values['key'] ?? null,
            'ca' => $values['ca'] ?? null,
            'force' => $values['force'] ?? $record->sslForce,
            'hsts' => $values['hsts'] ?? $record->sslHsts,
        ];
        if ($uploaded && $record->letsEncrypt) {
            return [
                new Step(__('Delete the Let’s Encrypt certificate'), 'v-delete-letsencrypt-domain', [$record->user, $record->domain, ''], ['web', 'proxy']),
                ...$this->ssl->plan($record->withoutSsl(), null, $input),
            ];
        }

        return $this->ssl->plan($record, $record->ssl ? $this->web->ssl($record->user, $record->domain) : null, $input);
    }

    /**
     * Turn SSL off: the certificate (Let's Encrypt or uploaded), forced HTTPS
     * and HSTS.
     *
     * @return list<Step>
     */
    public function planSslOff(WebDomainRecord $record): array
    {
        return $record->ssl ? $this->ssl->plan($record, null, ['ssl' => false, 'letsencrypt' => false]) : [];
    }

    /**
     * The General tab renews a Let's Encrypt certificate at once; API v2
     * queues it instead (E34 spec D15).
     *
     * @param  list<Step>  $steps
     * @return list<Step>
     */
    private static function queueLetsEncrypt(array $steps): array
    {
        return array_map(fn (Step $step) => $step->command === 'v-add-letsencrypt-domain'
            ? new Step(__('Queue a Let’s Encrypt certificate'), 'v-schedule-letsencrypt-domain', [$step->args[0], $step->args[1], $step->args[2], $step->args[4]])
            : $step, $steps);
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
