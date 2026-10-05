<?php

namespace App\Services\Mail;

/**
 * Sends the "account ready" mail of web/add/user to a new user.
 */
interface AccountMailer
{
    public function sendAccountCredentials(string $to, string $username, string $name, string $password, string $language): void;
}
