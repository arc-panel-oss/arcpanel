<?php

namespace App\Auth;

final class LoginResult
{
    public const SUCCESS = 'success';

    public const SECOND_FACTOR = 'second-factor';

    public const FAILED = 'failed';

    /** @param  array<string, string>  $user  record from `v-list-user USER json` */
    private function __construct(
        public readonly string $status,
        public readonly string $username = '',
        public readonly array $user = [],
        public readonly string $error = '',
    ) {}

    /** @param  array<string, string>  $user */
    public static function success(string $username, array $user): self
    {
        return new self(self::SUCCESS, $username, $user);
    }

    /** @param  array<string, string>  $user */
    public static function secondFactor(string $username, array $user): self
    {
        return new self(self::SECOND_FACTOR, $username, $user);
    }

    /** $error is an untranslated legacy msgid. */
    public static function failed(string $error): self
    {
        return new self(self::FAILED, error: $error);
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function needsSecondFactor(): bool
    {
        return $this->status === self::SECOND_FACTOR;
    }
}
