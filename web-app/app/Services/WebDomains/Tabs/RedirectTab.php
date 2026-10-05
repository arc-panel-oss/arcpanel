<?php

namespace App\Services\WebDomains\Tabs;

use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainRecord;

/**
 * Redirect tab: to www., to the bare domain or to a custom URL, 301 or 302
 * (web/edit/web/index.php).
 */
final class RedirectTab
{
    /**
     * @param  array{enabled?: bool, target?: ?string, custom?: ?string, code?: int|string|null}  $input
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, array $input): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        if (! ($input['enabled'] ?? false)) {
            if ($current->redirect !== '') {
                $steps[] = new Step(__('Delete the redirect'), 'v-delete-web-domain-redirect', [$user, $domain], ['web', 'proxy']);
            }

            return $steps;
        }

        $target = self::target($domain, (string) ($input['target'] ?? ''), (string) ($input['custom'] ?? ''));
        $code = (int) ($input['code'] ?? 301);
        if ($target === '') {
            return $steps;
        }
        if ($current->redirect === '' || $target !== $current->redirect || $code !== $current->redirectCode) {
            $steps[] = new Step(__('Change the redirect'), 'v-add-web-domain-redirect', [$user, $domain, $target, (string) $code], ['web', 'proxy']);
        }

        return $steps;
    }

    /** "www" → www.DOMAIN, "bare" → DOMAIN, "custom" → the custom URL. */
    public static function target(string $domain, string $choice, string $custom): string
    {
        return match ($choice) {
            'www' => 'www.'.$domain,
            'bare' => $domain,
            'custom' => trim($custom),
            default => '',
        };
    }

    /** Which choice the stored redirect corresponds to. */
    public static function choice(WebDomainRecord $current): string
    {
        return match ($current->redirect) {
            'www.'.$current->domain => 'www',
            $current->domain => 'bare',
            '' => 'www',
            default => 'custom',
        };
    }
}
