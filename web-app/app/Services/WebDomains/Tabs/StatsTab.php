<?php

namespace App\Services\WebDomains\Tabs;

use App\Arc\Secret;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainRecord;

/**
 * Statistics tab: the web statistics engine and its login
 * (web/edit/web/index.php).
 */
final class StatsTab
{
    /**
     * @param  array{type?: ?string, auth?: bool, user?: ?string, password?: ?string}  $input
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, array $input): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        $type = (string) ($input['type'] ?? 'none');
        $type = $type !== '' ? $type : 'none';
        if ($current->stats !== '' && $type === 'none') {
            $steps[] = new Step(__('Disable statistics'), 'v-delete-web-domain-stats', [$user, $domain]);
        } elseif ($current->stats !== '' && $type !== $current->stats) {
            $steps[] = new Step(__('Change the statistics engine'), 'v-change-web-domain-stats', [$user, $domain, $type]);
        } elseif ($current->stats === '' && $type !== 'none') {
            $steps[] = new Step(__('Enable statistics'), 'v-add-web-domain-stats', [$user, $domain, $type]);
        }

        $auth = (bool) ($input['auth'] ?? false);
        $statsUser = trim((string) ($input['user'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($current->statsUser !== '' && ! $auth) {
            $steps[] = new Step(__('Remove the statistics login'), 'v-delete-web-domain-stats-user', [$user, $domain]);
        } elseif ($auth && $statsUser !== '' && ($current->statsUser === '' || $statsUser !== $current->statsUser || $password !== '')) {
            $steps[] = new Step(__('Change the statistics login'), 'v-add-web-domain-stats-user', [$user, $domain, $statsUser, Secret::file($password)]);
        }

        return $steps;
    }
}
