<?php

namespace App\Auth;

use RuntimeException;

/**
 * LegacySession on PHP's native `files` session handler: same files, same
 * cookie and same serialization as web/inc/main.php. Cookie flags come from
 * the PHP-FPM pool (src/deb/php/php-fpm.conf), as for the legacy app.
 */
final class NativeLegacySession implements LegacySession
{
    /** @param  array<string, string>  $cookies */
    public function __construct(
        private readonly string $savePath,
        private readonly string $name,
        private readonly array $cookies,
    ) {}

    public function exists(): bool
    {
        return ($this->cookies[$this->name] ?? '') !== '';
    }

    public function read(): array
    {
        if (! $this->exists()) {
            return [];
        }
        $this->open();

        return $_SESSION;
    }

    public function write(array $patch): void
    {
        $this->open();
        $this->apply($patch);
        session_write_close();
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function establish(array $patch): void
    {
        $this->open();
        $this->apply($patch);
        session_regenerate_id(true);
        session_write_close();
    }

    public function destroy(): void
    {
        if (! $this->exists() && session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $this->open();
        $_SESSION = [];
        session_unset();
        session_destroy();

        $params = session_get_cookie_params();
        setcookie($this->name, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Strict',
        ]);
    }

    public function id(): string
    {
        return session_id() ?: (string) ($this->cookies[$this->name] ?? '');
    }

    private function open(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($this->name);
        session_save_path($this->savePath);
        if (! session_start()) {
            throw new RuntimeException('Cannot start the legacy session in '.$this->savePath);
        }
    }

    /** @param  array<string, mixed>  $patch */
    private function apply(array $patch): void
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
    }
}
