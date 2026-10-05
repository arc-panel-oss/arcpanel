<?php

namespace App\Services\WebDomains\Tabs;

use App\Services\WebDomains\DomainProbe;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainRecord;

/**
 * General tab: IP address, aliases and custom document root, with the
 * commands and order of web/edit/web/index.php.
 */
final class GeneralTab
{
    /**
     * @param  array{aliases?: ?string, ip?: ?string, docroot_custom?: bool, docroot_domain?: ?string, docroot_folder?: ?string}  $input
     * @param  array<string, array<string, string>>  $userIps  `v-list-user-ips USER json`
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, array $input, array $userIps, DomainProbe $probe): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        $public = function (string $ip) use ($userIps): string {
            $nat = trim((string) ($userIps[$ip]['NAT'] ?? ''));

            return $nat !== '' ? $nat : $ip;
        };

        // IP address, then the DNS zones and mail domain that follow it
        $newIp = trim((string) ($input['ip'] ?? ''));
        $ipChanged = $newIp !== '' && $newIp !== $current->ip;
        if ($ipChanged) {
            $steps[] = new Step(__('Change IP address'), 'v-change-web-domain-ip', [$user, $domain, $newIp, 'no'], ['web', 'proxy']);
            if ($probe->dnsDomainExists($user, $domain)) {
                $steps[] = new Step(__('Change the IP address of the DNS zone'), 'v-change-dns-domain-ip', [$user, $domain, $public($newIp), 'no'], ['dns']);
            }
            foreach ($current->aliases as $alias) {
                if ($probe->dnsDomainExists($user, $alias)) {
                    $steps[] = new Step(sprintf(__('Change the IP address of the DNS zone %s'), $alias), 'v-change-dns-domain-ip', [$user, $alias, $public($newIp)], ['dns']);
                }
            }
            if ($probe->mailDomainExists($user, $domain)) {
                $steps[] = new Step(__('Rebuild the mail domain'), 'v-rebuild-mail-domain', [$user, $domain]);
            }
        }

        // Aliases, with their DNS records when the domain has a DNS zone
        $aliases = Input::list($input['aliases'] ?? '');
        $removed = array_values(array_diff($current->aliases, $aliases));
        $added = array_values(array_diff($aliases, $current->aliases));
        $hasZone = ($removed !== [] || $added !== []) && $probe->dnsDomainExists($user, $domain);
        $aliasIp = $public($ipChanged ? $newIp : $current->ip);

        foreach ($removed as $alias) {
            $steps[] = new Step(sprintf(__('Delete alias %s'), $alias), 'v-delete-web-domain-alias', [$user, $domain, $alias, 'no'], ['web', 'proxy']);
            if ($hasZone) {
                $steps[] = new Step(sprintf(__('Delete the DNS record of %s'), $alias), 'v-delete-dns-on-web-alias', [$user, $domain, $alias, 'no'], ['dns']);
            }
        }
        foreach ($added as $alias) {
            $steps[] = new Step(sprintf(__('Add alias %s'), $alias), 'v-add-web-domain-alias', [$user, $domain, $alias, 'no'], ['web', 'proxy']);
            if ($hasZone) {
                $steps[] = new Step(sprintf(__('Add a DNS record for %s'), $alias), 'v-add-dns-on-web-alias', [$user, $alias, $aliasIp, 'no'], ['dns']);
            }
        }

        if ($removed !== [] || $added !== []) {
            // The Let's Encrypt certificate must cover the new aliases.
            if ($current->ssl && $current->letsEncrypt) {
                $steps[] = new Step(__('Renew the Let’s Encrypt certificate'), 'v-add-letsencrypt-domain', [$user, $domain, implode(',', $aliases), '', $current->sslKeyAlgo], ['web', 'proxy']);
            }
            // Statistics list the aliases of the domain.
            if ($current->stats !== '') {
                $steps[] = new Step(__('Update the statistics configuration'), 'v-change-web-domain-stats', [$user, $domain, $current->stats]);
            }
        }

        // Custom document root
        $parts = $current->customDocrootParts();
        if (! ($input['docroot_custom'] ?? false)) {
            if ($current->customDocroot !== '') {
                $steps[] = new Step(__('Reset the document root'), 'v-change-web-domain-docroot', [$user, $domain, 'default'], ['web', 'proxy']);
            }
        } else {
            $target = trim((string) ($input['docroot_domain'] ?? ''));
            $target = $target !== '' ? $target : $domain;
            $folder = trim(trim((string) ($input['docroot_folder'] ?? '')), '/');
            if ($target !== ($parts['domain'] ?? $domain) || $folder !== ($parts['folder'] ?? '')) {
                $steps[] = $target === $domain && $folder === ''
                    ? new Step(__('Reset the document root'), 'v-change-web-domain-docroot', [$user, $domain, 'default'], ['web', 'proxy'])
                    : new Step(__('Change the document root'), 'v-change-web-domain-docroot', [$user, $domain, $target, $folder, 'yes'], ['web', 'proxy']);
            }
        }

        return $steps;
    }
}
