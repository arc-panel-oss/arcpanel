<?php

namespace App\Auth;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * Mail account password change for the webmail clients on this server
 * (the Roundcube password driver and the SnappyMail plugin post to
 * /reset/mail/). It replaces web/reset/mail/index.php of 1.x, which E8
 * removed, and keeps its answers: "==ok==" or a line that starts with
 * "error".
 */
final class MailPasswordChange
{
    /** Attempts per mail address in DECAY seconds. */
    public const ATTEMPTS = 5;

    public const DECAY = 900;

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * Only the server itself may call: loopback, the address the request
     * came in on, or one of the server's IP addresses or their NAT
     * addresses (legacy behaviour).
     */
    public function allowedFrom(string $remote, string $local): bool
    {
        if ($remote === '') {
            return false;
        }
        if (in_array($remote, ['127.0.0.1', '::1'], true) || $remote === $local) {
            return true;
        }
        foreach ($this->arc->json('v-list-sys-ips', [], Options::allowFailure()) as $address => $row) {
            if ($remote === (string) $address || $remote === (string) (is_array($row) ? ($row['NAT'] ?? '') : '')) {
                return true;
            }
        }

        return false;
    }

    /** "==ok==" when the old password matches and the new one is set, otherwise "error …". */
    public function change(string $email, string $old, string $new): string
    {
        [$account, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($account === '' || $domain === '') {
            return 'error email address not valid';
        }

        $owner = $this->arc->run('v-search-domain-owner', [$domain, 'mail'], Options::allowFailure());
        $user = $owner->ok() ? trim($owner->lines()[0] ?? '') : '';
        if ($user === '') {
            return 'error domain owner not found';
        }

        $value = $this->arc->run('v-get-mail-account-value', [$user, $domain, $account, 'md5'], Options::allowFailure());
        $hash = $value->ok() ? trim($value->lines()[0] ?? '') : '';
        if ($hash === '') {
            return 'error unable to get current account password hash';
        }

        // Dovecot format {SCHEME}crypt: password_verify needs the crypt part
        $parts = explode('}', $hash, 2);
        if (! password_verify($old, end($parts))) {
            return 'error old password does not match';
        }

        $result = $this->arc->run('v-change-mail-account-password', [$user, $domain, $account, Secret::file($new)], Options::allowFailure());

        return $result->ok() ? '==ok==' : 'error v-change-mail-account-password returned non-zero: '.$result->exitCode;
    }
}
