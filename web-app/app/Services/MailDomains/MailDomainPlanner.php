<?php

namespace App\Services\MailDomains;

use App\Arc\Secret;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;

/**
 * The commands of adding and editing a mail domain, in the order of
 * web/add/mail and web/edit/mail, only for the values that changed. The
 * mail commands restart the services themselves, so no step asks for a
 * restart.
 *
 * $systems tells which optional fields the form showed: antispam
 * (ANTISPAM_SYSTEM: spam filter and reject), antivirus (ANTIVIRUS_SYSTEM)
 * and webmail (IMAP_SYSTEM and WEBMAIL_SYSTEM). A field the form did not
 * show changes nothing.
 */
final class MailDomainPlanner
{
    /** "Disabled" in the webmail select. */
    public const WEBMAIL_DISABLED = 'disabled';

    /** SMTP relay port when the field is empty (web/add/mail). */
    public const RELAY_PORT = '587';

    /**
     * web/add/mail: add the domain, then reject spam, webmail (add or
     * delete) and the SMTP relay.
     *
     * @param  array{domain: string, antispam: bool, antivirus: bool, dkim: bool, reject: bool, webmail: string, relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: string}  $input
     * @param  array{antispam: bool, antivirus: bool, webmail: bool}  $systems
     * @return list<Step>
     */
    public function planCreate(string $owner, array $input, array $systems): array
    {
        $domain = $input['domain'];
        $antispam = $systems['antispam'] && $input['antispam'];
        $antivirus = $systems['antivirus'] && $input['antivirus'];

        $steps = [new Step(__('Add the mail domain'), 'v-add-mail-domain',
            [$owner, $domain, self::yesNo($antispam), self::yesNo($antivirus), self::yesNo($input['dkim'])])];

        if ($antispam && $input['reject']) {
            $steps[] = new Step(__('Enable Reject Spam'), 'v-add-mail-domain-reject', [$owner, $domain]);
        }
        if ($systems['webmail']) {
            $steps[] = self::webmailStep($owner, $domain, $input['webmail']);
        }
        if ($input['relay'] && $input['relay_host'] !== '') {
            $steps[] = $this->relayStep($owner, $domain, $input);
        }

        return $steps;
    }

    /**
     * General tab of web/edit/mail: spam filter, anti-virus and DKIM; the
     * catch-all deletion; the rate limit (administrators); reject spam; the
     * catch-all change or addition; the webmail client, only when it
     * changed (spec §11).
     *
     * @param  array{webmail: string|null, catchall: string, rate: string|null, antispam: bool, reject: bool, antivirus: bool, dkim: bool}  $input  rate null: the field was not editable
     * @param  array{antispam: bool, antivirus: bool, webmail: bool}  $systems
     * @return list<Step>
     */
    public function planGeneral(MailDomainRecord $current, array $input, array $systems): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $args = [$user, $domain];
        $steps = [];

        $antispam = $current->antispam;
        if ($systems['antispam'] && $antispam !== $input['antispam']) {
            $steps[] = $input['antispam']
                ? new Step(__('Enable the spam filter'), 'v-add-mail-domain-antispam', $args)
                : new Step(__('Disable the spam filter'), 'v-delete-mail-domain-antispam', $args);
            $antispam = $input['antispam'];
        }
        if ($systems['antivirus'] && $current->antivirus !== $input['antivirus']) {
            $steps[] = $input['antivirus']
                ? new Step(__('Enable the anti-virus'), 'v-add-mail-domain-antivirus', $args)
                : new Step(__('Disable the anti-virus'), 'v-delete-mail-domain-antivirus', $args);
        }
        if ($current->dkim !== $input['dkim']) {
            $steps[] = $input['dkim']
                ? new Step(__('Enable DKIM'), 'v-add-mail-domain-dkim', $args)
                : new Step(__('Disable DKIM'), 'v-delete-mail-domain-dkim', $args);
        }

        $catchall = $input['catchall'];
        if ($current->catchall !== '' && $catchall === '') {
            $steps[] = new Step(__('Delete the catch-all address'), 'v-delete-mail-domain-catchall', $args);
        }

        if ($input['rate'] !== null && $input['rate'] !== $current->rateLimit) {
            $steps[] = new Step(__('Change the rate limit'), 'v-change-mail-domain-rate-limit',
                [$user, $domain, $input['rate'] !== '' ? $input['rate'] : 'system']);
        }

        if ($systems['antispam']) {
            if ($input['reject'] && $antispam && ! $current->reject) {
                $steps[] = new Step(__('Enable Reject Spam'), 'v-add-mail-domain-reject', $args);
            } elseif (! $input['reject'] && $current->reject) {
                $steps[] = new Step(__('Disable Reject Spam'), 'v-delete-mail-domain-reject', $args);
            }
        }

        if ($current->catchall !== '' && $catchall !== '' && $catchall !== $current->catchall) {
            $steps[] = new Step(__('Change the catch-all address'), 'v-change-mail-domain-catchall', [$user, $domain, $catchall]);
        } elseif ($current->catchall === '' && $catchall !== '') {
            $steps[] = new Step(__('Add the catch-all address'), 'v-add-mail-domain-catchall', [$user, $domain, $catchall]);
        }

        if ($systems['webmail'] && $input['webmail'] !== null) {
            $wanted = $input['webmail'] === self::WEBMAIL_DISABLED ? '' : $input['webmail'];
            if ($wanted !== $current->webmail) {
                $steps[] = self::webmailStep($user, $domain, $input['webmail']);
            }
        }

        return $steps;
    }

    /**
     * SSL tab of web/edit/mail, in its order: replace the uploaded
     * certificate; reissue Let's Encrypt with another key type; delete Let's
     * Encrypt; delete SSL; issue Let's Encrypt; install an uploaded
     * certificate. Switching from Let's Encrypt to an uploaded certificate
     * takes two saves (the deletion of Let's Encrypt removes SSL).
     *
     * @param  array{ssl: bool, letsencrypt: bool, key_algo: string, certificate: ?string, key: ?string, ca: ?string}  $input
     * @return list<Step>
     */
    public function planSsl(MailDomainRecord $current, ?MailDomainSsl $certificate, array $input, bool $replaceLetsEncrypt = false): array
    {
        $user = $current->user;
        $domain = $current->domain;
        $steps = [];

        $sslOn = $input['ssl'];
        $leOn = $input['letsencrypt'];
        $algo = in_array($input['key_algo'], MailDomainRecord::KEY_ALGORITHMS, true) ? $input['key_algo'] : 'ecdsa-256';
        $crt = Input::pem($input['certificate'] ?? '');
        $key = Input::pem($input['key'] ?? '');
        $ca = Input::pem($input['ca'] ?? '');

        // The API replaces Let's Encrypt with an uploaded certificate in one
        // plan. The page still needs two saves (the flag stays false there).
        if ($replaceLetsEncrypt && $current->letsEncrypt && $sslOn && ! $leOn && $crt !== '') {
            return [
                new Step(__('Delete the Let’s Encrypt certificate'), 'v-delete-letsencrypt-domain', [$user, $domain, '', 'yes']),
                new Step(__('Install the SSL certificate'), 'v-add-mail-domain-ssl',
                    [$user, $domain, $this->files($domain, $crt, $key, $ca, ''), 'no']),
            ];
        }

        $ssl = $current->ssl;
        $le = $current->letsEncrypt;
        $leDeleted = false;

        // Replace the uploaded certificate (an empty key keeps the current one)
        if (! $le && ! $leOn && $ssl && $sslOn && $crt !== '') {
            $currentKey = Input::pem($certificate?->key());
            $changed = $crt !== Input::pem($certificate?->certificate)
                || ($key !== '' && $key !== $currentKey)
                || $ca !== Input::pem($certificate?->ca);
            if ($changed) {
                $steps[] = new Step(__('Change the SSL certificate'), 'v-change-mail-domain-sslcert',
                    [$user, $domain, $this->files($domain, $crt, $key !== '' ? $key : $currentKey, $ca, "\n"), 'no']);
            }
        }

        // Reissue Let's Encrypt with another key type
        if ($sslOn && $le && $leOn && $algo !== $current->keyAlgo()) {
            $steps[] = new Step(__('Issue a Let’s Encrypt certificate'), 'v-add-letsencrypt-domain', [$user, $domain, '', 'yes', $algo]);
        }

        // Turn Let's Encrypt off; this also removes the certificate
        if ($le && (! $leOn || ! $sslOn)) {
            $steps[] = new Step(__('Delete the Let’s Encrypt certificate'), 'v-delete-letsencrypt-domain', [$user, $domain, '', 'yes']);
            $le = false;
            $ssl = false;
            $leDeleted = true;
        }

        // Turn SSL off
        if ($ssl && ! $sslOn) {
            $steps[] = new Step(__('Disable SSL'), 'v-delete-mail-domain-ssl', [$user, $domain]);
            $ssl = false;
        }

        // Issue a Let's Encrypt certificate
        if ($sslOn && ! $le && $leOn) {
            $steps[] = new Step(__('Issue a Let’s Encrypt certificate'), 'v-add-letsencrypt-domain', [$user, $domain, '', 'yes', $algo]);
            $ssl = true;
        }

        // Install an uploaded certificate
        if (! $ssl && $sslOn && ! $leDeleted) {
            $steps[] = new Step(__('Install the SSL certificate'), 'v-add-mail-domain-ssl',
                [$user, $domain, $this->files($domain, $crt, $key, $ca, ''), 'no']);
        }

        return $steps;
    }

    /**
     * Whether the SSL tab installs an uploaded certificate, which needs the
     * certificate and its key (web/edit/mail "Field … can not be blank").
     *
     * @param  array{ssl: bool, letsencrypt: bool}  $input
     */
    public static function installsCertificate(MailDomainRecord $current, array $input): bool
    {
        return ! $current->ssl && $input['ssl'] && ! $input['letsencrypt'];
    }

    /**
     * SMTP relay tab: add the relay again when the host, user or port
     * changed or a password was entered; delete it when switched off.
     *
     * @param  array{relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: string}  $input
     * @return list<Step>
     */
    public function planRelay(MailDomainRecord $current, array $input): array
    {
        if ($input['relay'] && $input['relay_host'] !== '') {
            $port = $input['relay_port'] !== '' ? $input['relay_port'] : self::RELAY_PORT;
            $changed = $input['relay_host'] !== $current->relayHost
                || $input['relay_user'] !== $current->relayUser
                || $port !== $current->relayPort
                || $input['relay_password'] !== '';

            return $changed ? [$this->relayStep($current->user, $current->domain, $input)] : [];
        }
        if (! $input['relay'] && $current->relay) {
            return [new Step(__('Delete the SMTP relay'), 'v-delete-mail-domain-smtp-relay', [$current->user, $current->domain])];
        }

        return [];
    }

    /** v-add-mail-domain-webmail CLIENT, or v-delete-mail-domain-webmail for "Disabled" (spec §11). */
    private static function webmailStep(string $user, string $domain, string $client): Step
    {
        if ($client === '' || $client === self::WEBMAIL_DISABLED) {
            return new Step(__('Disable webmail'), 'v-delete-mail-domain-webmail', [$user, $domain, 'yes']);
        }

        return new Step(__('Change the webmail client'), 'v-add-mail-domain-webmail', [$user, $domain, $client, 'yes']);
    }

    /** @param  array{relay_host: string, relay_port: string, relay_user: string, relay_password: string}  $input */
    private function relayStep(string $user, string $domain, array $input): Step
    {
        return new Step(__('Change the SMTP relay'), 'v-add-mail-domain-smtp-relay', [
            $user, $domain, $input['relay_host'], $input['relay_user'], Secret::file($input['relay_password']),
            $input['relay_port'] !== '' ? $input['relay_port'] : self::RELAY_PORT,
        ]);
    }

    /** SSL_DIR content; web/edit/mail ends the files with a newline when replacing a certificate. */
    private function files(string $domain, string $crt, string $key, string $ca, string $end): Secret
    {
        $files = [$domain.'.crt' => $crt.$end, $domain.'.key' => $key.$end];
        if ($ca !== '') {
            $files[$domain.'.ca'] = $ca.$end;
        }

        return Secret::directory($files);
    }

    private static function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
