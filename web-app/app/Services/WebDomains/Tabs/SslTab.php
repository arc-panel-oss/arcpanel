<?php

namespace App\Services\WebDomains\Tabs;

use App\Arc\Secret;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainRecord;
use App\Services\WebDomains\WebDomainSsl;

/**
 * SSL tab: certificate, Let's Encrypt, forced HTTPS and HSTS, in the order
 * of web/edit/web/index.php. Certificates go to the commands as a secret
 * directory (DOMAIN.crt, DOMAIN.key, DOMAIN.ca).
 */
final class SslTab
{
    /**
     * @param  array{ssl?: bool, letsencrypt?: bool, key_algo?: ?string, certificate?: ?string, key?: ?string, ca?: ?string, force?: bool, hsts?: bool}  $input
     * @return list<Step>
     */
    public function plan(WebDomainRecord $current, ?WebDomainSsl $certificate, array $input): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        $sslOn = (bool) ($input['ssl'] ?? false);
        $leOn = (bool) ($input['letsencrypt'] ?? false);
        $algo = (string) ($input['key_algo'] ?? $current->sslKeyAlgo);
        $crt = Input::pem($input['certificate'] ?? '');
        $key = Input::pem($input['key'] ?? '');
        $ca = Input::pem($input['ca'] ?? '');

        $ssl = $current->ssl;
        $le = $current->letsEncrypt;
        $force = $current->sslForce;
        $hsts = $current->sslHsts;
        $leDeleted = false;

        // Replace the uploaded certificate (an empty key keeps the current one)
        if (! $le && ! $leOn && $ssl && $sslOn && $crt !== '') {
            $currentKey = Input::pem($certificate?->key());
            $changed = $crt !== Input::pem($certificate?->certificate)
                || ($key !== '' && $key !== $currentKey)
                || $ca !== Input::pem($certificate?->ca);
            if ($changed) {
                $steps[] = new Step(__('Change the SSL certificate'), 'v-change-web-domain-sslcert',
                    [$user, $domain, $this->files($domain, $crt, $key !== '' ? $key : $currentKey, $ca, "\n"), 'no'], ['web', 'proxy']);
            }
        }

        // Turn Let's Encrypt off; this also removes the certificate
        if ($le && (! $leOn || ! $sslOn)) {
            $steps[] = new Step(__('Delete the Let’s Encrypt certificate'), 'v-delete-letsencrypt-domain', [$user, $domain, ''], ['web', 'proxy']);
            $le = false;
            $ssl = false;
            $leDeleted = true;
        }

        // Turn SSL off
        if ($ssl && ! $sslOn) {
            $steps[] = new Step(__('Disable SSL'), 'v-delete-web-domain-ssl', [$user, $domain, 'no'], ['web', 'proxy']);
            $ssl = false;
            $force = false;
            $hsts = false;
        }

        // Issue a Let's Encrypt certificate, or reissue it with another key type
        if ($sslOn && $leOn && (! $le || $algo !== $current->sslKeyAlgo)) {
            $steps[] = new Step(__('Issue a Let’s Encrypt certificate'), 'v-add-letsencrypt-domain',
                [$user, $domain, implode(',', $current->aliases), '', $algo], ['web', 'proxy']);
            $ssl = true;
            $le = true;
        }

        // Install an uploaded certificate
        if (! $ssl && $sslOn && ! $leOn && ! $leDeleted) {
            $steps[] = new Step(__('Install the SSL certificate'), 'v-add-web-domain-ssl',
                [$user, $domain, $this->files($domain, $crt, $key, $ca, ''), $current->sslHome, 'no'], ['web', 'proxy']);
            $ssl = true;
        }

        $forceOn = $sslOn && (bool) ($input['force'] ?? false);
        $hstsOn = $sslOn && (bool) ($input['hsts'] ?? false);
        if ($forceOn && ! $force) {
            $steps[] = new Step(__('Enable automatic HTTPS redirection'), 'v-add-web-domain-ssl-force', [$user, $domain], ['web', 'proxy']);
        }
        if ($hstsOn && ! $hsts) {
            $steps[] = new Step(__('Enable HSTS'), 'v-add-web-domain-ssl-hsts', [$user, $domain], ['web', 'proxy']);
        }
        if ($force && ! $forceOn) {
            $steps[] = new Step(__('Disable automatic HTTPS redirection'), 'v-delete-web-domain-ssl-force', [$user, $domain], ['web', 'proxy']);
        }
        if ($hsts && ! $hstsOn) {
            $steps[] = new Step(__('Disable HSTS'), 'v-delete-web-domain-ssl-hsts', [$user, $domain], ['web', 'proxy']);
        }

        return $steps;
    }

    /** SSL_DIR content; web/edit/web ends the files with a newline when replacing a certificate. */
    private function files(string $domain, string $crt, string $key, string $ca, string $end): Secret
    {
        $files = [$domain.'.crt' => $crt.$end, $domain.'.key' => $key.$end];
        if ($ca !== '') {
            $files[$domain.'.ca'] = $ca.$end;
        }

        return Secret::directory($files);
    }
}
