<?php

namespace App\Auth;

/**
 * The legacy PHP session shared with web/ and the web terminal: the only code
 * in the panel app that touches $_SESSION.
 *
 * read() opens and locks the session file; write() and close() release it.
 * Callers keep the lock as short as possible: a long v-* call must not run
 * while the session is open.
 */
interface LegacySession
{
    /** Whether the request carries a legacy session cookie. */
    public function exists(): bool;

    /**
     * Open the session (if a cookie exists) and return its data.
     *
     * @return array<string, mixed>
     */
    public function read(): array;

    /**
     * Merge $patch into the session and release it. A null value removes the key.
     *
     * @param  array<string, mixed>  $patch
     */
    public function write(array $patch): void;

    /** Release the session without changes. */
    public function close(): void;

    /**
     * Log a user in: start a session if needed, apply $patch, then give the
     * session a new id (session fixation). Keys of a guest session are kept.
     *
     * @param  array<string, mixed>  $patch
     */
    public function establish(array $patch): void;

    /** Delete the session (web/inc/main.php destroy_sessions()). */
    public function destroy(): void;

    public function id(): string;
}
