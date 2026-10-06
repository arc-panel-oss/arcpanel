<?php

namespace App\Services\Mail;

/**
 * Sends the reset code of web/reset (E7 batch 3 spec §11.2).
 */
interface PasswordResetMailer
{
    /** $host: host name, with the port when the request came on the panel port. */
    public function sendPasswordReset(string $to, string $name, string $username, string $code, string $language, string $host): void;
}
