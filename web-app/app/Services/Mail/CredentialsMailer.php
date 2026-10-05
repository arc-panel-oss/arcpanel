<?php

namespace App\Services\Mail;

/**
 * Sends account credentials by e-mail, as the legacy pages do with
 * send_email() (web/inc/main.php).
 */
interface CredentialsMailer
{
    public function sendFtpCredentials(string $to, string $domain, string $username, string $password, string $language): void;
}
