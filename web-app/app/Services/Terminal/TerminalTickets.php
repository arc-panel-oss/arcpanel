<?php

namespace App\Services\Terminal;

/**
 * Tickets of the web terminal (E39 W2 spec §8, D13). The panel checks the
 * session and the terminal policy, then signs a ticket for the effective
 * user; the terminal server (src/deb/web-terminal/ticket.js) checks the
 * signature and the expiry and accepts each nonce once. The key is written
 * by v-add-sys-web-terminal-key, readable by the panel user only.
 */
final class TerminalTickets
{
    /** Seconds a ticket is valid: long enough to open the WebSocket. */
    public const LIFETIME = 30;

    public function available(): bool
    {
        return $this->key() !== null;
    }

    /** "<payload>.<signature>", both base64url; null when the key is missing. */
    public function issue(string $user): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }
        $payload = self::base64url((string) json_encode([
            'u' => $user,
            'exp' => time() + self::LIFETIME,
            'n' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::base64url(hash_hmac('sha256', $payload, $key, true));
    }

    /** The raw key, or null when the file is missing or malformed. */
    private function key(): ?string
    {
        $path = (string) config('arc.terminal_key');
        $hex = is_readable($path) ? trim((string) file_get_contents($path)) : '';
        if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
            return null;
        }

        return (string) hex2bin($hex);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
