<?php

namespace App\Services\MailDomains;

use App\Services\WebDomains\Input;
use App\Tenancy\TenancyDirectory;
use Illuminate\Validation\ValidationException;

/**
 * Checks the mail pages and API v2 share (E34 batch 2 spec D25): a webmail
 * client the server has, and the key of a first uploaded certificate.
 */
final class MailActions
{
    public function __construct(
        private readonly MailService $mail,
        private readonly TenancyDirectory $directory,
    ) {}

    /** The owner exists; 404 otherwise (an administrator may name any user). */
    public function ownerExists(string $owner): bool
    {
        return array_key_exists($owner, $this->directory->all());
    }

    /**
     * A webmail client must be one of the server's, or "disabled".
     *
     * @throws ValidationException
     */
    public function assertWebmail(string $client): void
    {
        if ($client !== '' && $client !== MailDomainPlanner::WEBMAIL_DISABLED && ! in_array($client, $this->mail->webmailClients(), true)) {
            throw ValidationException::withMessages(['webmail' => __('Please select a valid webmail client.')]);
        }
    }

    /**
     * An uploaded certificate that installs SSL needs the certificate and its key
     * (web/edit/mail "Field … can not be blank"). Replacing Let's Encrypt counts
     * as a first install: the deletion removes the current certificate.
     *
     * @param  array{ssl: bool, letsencrypt: bool, certificate: ?string, key: ?string}  $values
     *
     * @throws ValidationException
     */
    public function assertCertificate(MailDomainRecord $record, array $values): void
    {
        $replacing = $record->letsEncrypt && $values['ssl'] && ! $values['letsencrypt']
            && Input::pem($values['certificate'] ?? '') !== '';
        if (! MailDomainPlanner::installsCertificate($record, $values) && ! $replacing) {
            return;
        }

        $missing = [];
        if (Input::pem($values['certificate'] ?? '') === '') {
            $missing['certificate'] = sprintf(__('Field "%s" can not be blank.'), __('SSL Certificate'));
        }
        if (Input::pem($values['key'] ?? '') === '') {
            $missing['key'] = sprintf(__('Field "%s" can not be blank.'), __('SSL Private Key'));
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }
}
