<?php

namespace App\Api;

use RuntimeException;

/**
 * An error answer of API v2: `{"error": {"code", "message", …}}` with its
 * HTTP status and headers (E34 spec D10).
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  more keys of the error object (fields, step, applied)
     * @param  array<string, string>  $headers
     * @param  string|array<string, mixed>|null  $existing  path under /api/v2, or a task (D21)
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $details = [],
        public readonly array $headers = [],
        public readonly string|array|null $existing = null,
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(string $message = 'Authentication failed.'): self
    {
        return new self(ErrorCode::Unauthenticated, $message, 401, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function apiDisabled(string $message = 'The API is disabled.'): self
    {
        return new self(ErrorCode::ApiDisabled, $message, 403);
    }

    /** @param  list<string>  $commands  commands the key may not run */
    public static function commandsRefused(array $commands): self
    {
        return new self(
            ErrorCode::Forbidden,
            'The permission sets of the access key do not allow '.implode(', ', $commands).'.',
            403,
            ['commands' => array_values($commands)],
        );
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(ErrorCode::RateLimit, 'Too many requests.', 429, headers: ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    /** @return array{error: array<string, mixed>} */
    public function body(): array
    {
        $error = ['code' => $this->errorCode->value, 'message' => $this->getMessage(), ...$this->details];
        if ($this->existing !== null) {
            $error['existing'] = $this->existing;
        }

        return ['error' => $error];
    }
}
