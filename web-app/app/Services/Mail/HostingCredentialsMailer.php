<?php

namespace App\Services\Mail;

/**
 * Sends database and mail account credentials by e-mail, as web/add/db and
 * web/add|edit/mail do (E7 batch 1 spec D12).
 */
interface HostingCredentialsMailer
{
    public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void;

    /**
     * @param  array{account: string, domain: string, password: string, webmail: string, hostname: string}  $details
     */
    public function sendMailCredentials(string $to, array $details, string $language): void;
}
