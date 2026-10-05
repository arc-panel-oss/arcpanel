<?php

namespace App\Services\Dashboard;

/**
 * Data of one dashboard widget, or the error that kept it from loading.
 */
final class Widget
{
    private function __construct(
        public readonly mixed $data,
        public readonly ?string $error,
    ) {}

    public static function loaded(mixed $data): self
    {
        return new self($data, null);
    }

    public static function failed(string $error): self
    {
        return new self(null, $error);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
