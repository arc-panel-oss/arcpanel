<?php

namespace App\Services\WebDomains\Tabs;

use App\Arc\Secret;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainRecord;
use Closure;

/**
 * FTP tab: additional FTP accounts of the domain (web/edit/web/index.php).
 * Passwords go to the commands as secret files; credentials can be mailed.
 */
final class FtpTab
{
    /**
     * @param  array{accounts?: list<array{user: string, path?: ?string, password?: ?string, email?: ?string, delete?: bool}>, new?: array{user?: ?string, password?: ?string, path?: ?string, email?: ?string}}  $input
     * @param  Closure(string $email, string $username, string $password): void  $mail  sends the credentials
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, array $input, Closure $mail): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        $existing = [];
        foreach ($current->ftpAccounts as $account) {
            $existing[$account['user']] = $account['path'];
        }

        foreach ($input['accounts'] ?? [] as $account) {
            $name = (string) ($account['user'] ?? '');
            if (! array_key_exists($name, $existing)) {
                continue;
            }
            if ($account['delete'] ?? false) {
                $steps[] = new Step(sprintf(__('Delete FTP account %s'), $name), 'v-delete-web-domain-ftp', [$user, $domain, $name]);

                continue;
            }
            $path = trim((string) ($account['path'] ?? ''));
            if ($path !== trim($existing[$name])) {
                $steps[] = new Step(sprintf(__('Change the path of FTP account %s'), $name), 'v-change-web-domain-ftp-path', [$user, $domain, $name, $path]);
            }
            $password = (string) ($account['password'] ?? '');
            $email = trim((string) ($account['email'] ?? ''));
            if ($password !== '') {
                $steps[] = new Step(sprintf(__('Change the password of FTP account %s'), $name), 'v-change-web-domain-ftp-password',
                    [$user, $domain, $name, Secret::file($password)], [],
                    $email !== '' ? fn () => $mail($email, $name, $password) : null);
            }
        }

        $new = $input['new'] ?? [];
        $newName = preg_replace('/^'.preg_quote($user.'_', '/').'/i', '', trim((string) ($new['user'] ?? ''))) ?? '';
        if ($newName !== '') {
            $password = (string) ($new['password'] ?? '');
            $email = trim((string) ($new['email'] ?? ''));
            $steps[] = new Step(sprintf(__('Add FTP account %s'), $user.'_'.$newName), 'v-add-web-domain-ftp',
                [$user, $domain, $newName, Secret::file($password), trim((string) ($new['path'] ?? ''))], [],
                $email !== '' ? fn () => $mail($email, $user.'_'.$newName, $password) : null);
        }

        return $steps;
    }
}
