<?php

namespace App\Services\WebDomains\Tabs;

use App\Arc\ExitCode;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\TemplateMeta;
use App\Services\WebDomains\WebDomainRecord;

/**
 * The backend port of a domain whose visitor-facing template takes one
 * (E6 spec §7.2): runs after the template commands of AdvancedTab.
 */
final class TemplateVarsTab
{
    public const COMMAND = 'v-change-web-domain-tpl-vars';

    /**
     * @param  ?TemplateMeta  $active  the visitor-facing template after the change
     * @param  ?string  $port  "auto", a port, or null/empty for "auto"
     * @param  array<string, string>  $vars  the domain's current variables
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, ?TemplateMeta $active, ?string $port, array $vars, bool $templateChanged): array
    {
        $key = $active?->portVariable();
        if ($key !== null) {
            $value = $port === null || $port === '' ? 'auto' : $port;
            $stored = $vars[$key] ?? '';
            $changed = $stored === '' || $templateChanged || ($value !== 'auto' && $value !== $stored);

            return $changed
                ? [new Step(__('Set the app port'), self::COMMAND, [$current->user, $current->domain, "{$key}={$value}"])]
                : [];
        }

        return $vars !== []
            ? [new Step(__('Release the app port'), 'v-delete-web-domain-tpl-vars', [$current->user, $current->domain])]
            : [];
    }

    /**
     * Name of the visitor-facing template once VALUES (the Advanced tab
     * fields) are applied: the proxy template on NGINX in front of Apache
     * (none with the proxy off), the web template on NGINX with PHP-FPM.
     *
     * @param  array{template?: ?string, proxy?: bool, proxy_template?: ?string}  $values
     */
    public static function activeTemplate(?string $kind, WebDomainRecord $record, array $values): ?string
    {
        if ($kind === 'web') {
            $template = trim((string) ($values['template'] ?? ''));

            return $template !== '' ? $template : $record->template;
        }
        if ($kind === 'proxy') {
            if (! ($values['proxy'] ?? $record->hasProxy())) {
                return null;
            }
            $template = trim((string) ($values['proxy_template'] ?? ''));

            return $template !== '' ? $template : ($record->proxyTemplate !== '' ? $record->proxyTemplate : 'default');
        }

        return null;
    }

    /** The visitor-facing template of a domain as it is now. */
    public static function currentTemplate(?string $kind, WebDomainRecord $record): ?string
    {
        return match ($kind) {
            'web' => $record->template,
            'proxy' => $record->hasProxy() ? ($record->proxyTemplate !== '' ? $record->proxyTemplate : 'default') : null,
            default => null,
        };
    }

    /** Message for the port field when the command refused the port. */
    public static function error(int $exit, string $message, string $owner): string
    {
        return match (ExitCode::tryFrom($exit)) {
            ExitCode::Forbidden => sprintf(__('This port is not in the port pool of %s.'), $owner),
            ExitCode::Limit => __('No free port is left in the pool. Ask your provider for more ports.'),
            ExitCode::InUse => sprintf(__('This port is already used: %s'), preg_replace('/^Error:\s*/', '', $message)),
            default => preg_replace('/^Error:\s*/', '', $message) ?: sprintf(__('Error code: %s'), $exit),
        };
    }
}
