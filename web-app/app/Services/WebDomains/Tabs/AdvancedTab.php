<?php

namespace App\Services\WebDomains\Tabs;

use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainRecord;

/**
 * Advanced tab: web, backend and proxy templates, proxy extensions and the
 * nginx FastCGI cache (web/edit/web/index.php, for administrators or with
 * POLICY_USER_EDIT_WEB_TEMPLATES).
 */
final class AdvancedTab
{
    /**
     * @param  array{template?: ?string, backend_template?: ?string, proxy?: bool, proxy_template?: ?string, proxy_extensions?: ?string, fastcgi_cache?: bool, fastcgi_duration?: ?string}  $input
     * @param  array{backend: bool, proxy: bool, nginx: bool}  $systems  WEB_BACKEND set, PROXY_SYSTEM set, WEB_SYSTEM is nginx
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, array $input, array $systems): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        $template = trim((string) ($input['template'] ?? ''));
        if ($template !== '' && $template !== $current->template) {
            $steps[] = new Step(__('Change the web template'), 'v-change-web-domain-tpl', [$user, $domain, $template, 'no'], ['web']);
        }

        $backend = trim((string) ($input['backend_template'] ?? ''));
        if ($systems['backend'] && $backend !== '' && $backend !== $current->backendTemplate) {
            $steps[] = new Step(__('Change the backend template'), 'v-change-web-domain-backend-tpl', [$user, $domain, $backend]);
        }

        if ($systems['nginx']) {
            $cacheOn = (bool) ($input['fastcgi_cache'] ?? false);
            $duration = trim((string) ($input['fastcgi_duration'] ?? ''));
            $duration = $duration !== '' ? $duration : '2m';
            if ($cacheOn && (! $current->fastcgiCache || $duration !== $current->fastcgiDuration)) {
                $steps[] = new Step(__('Enable the FastCGI cache'), 'v-add-fastcgi-cache', [$user, $domain, $duration], ['web']);
            } elseif (! $cacheOn && $current->fastcgiCache) {
                $steps[] = new Step(__('Disable the FastCGI cache'), 'v-delete-fastcgi-cache', [$user, $domain], ['web']);
            }
        }

        if ($systems['proxy']) {
            $proxyOn = (bool) ($input['proxy'] ?? false);
            $proxyTemplate = trim((string) ($input['proxy_template'] ?? ''));
            $extensions = implode(',', Input::list($input['proxy_extensions'] ?? ''));

            if ($current->hasProxy() && ! $proxyOn) {
                $steps[] = new Step(__('Disable the proxy'), 'v-delete-web-domain-proxy', [$user, $domain, 'no'], ['web']);
            } elseif ($current->hasProxy() && $proxyOn
                && (($proxyTemplate !== '' && $proxyTemplate !== $current->proxyTemplate) || $extensions !== implode(',', $current->proxyExtensions))) {
                $steps[] = new Step(__('Change the proxy template'), 'v-change-web-domain-proxy-tpl',
                    [$user, $domain, $proxyTemplate !== '' ? $proxyTemplate : $current->proxyTemplate, $extensions, 'no'], ['proxy']);
            } elseif (! $current->hasProxy() && $proxyOn) {
                $steps[] = new Step(__('Enable the proxy'), 'v-add-web-domain-proxy',
                    [$user, $domain, $proxyTemplate !== '' ? $proxyTemplate : 'default', $extensions, 'no'], ['proxy']);
            }
        }

        return $steps;
    }
}
