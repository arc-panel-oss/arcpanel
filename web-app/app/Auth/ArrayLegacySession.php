<?php

namespace App\Auth;

use PHPUnit\Framework\Assert;

/**
 * In-memory LegacySession for tests (native sessions cannot start once
 * PHPUnit has written output).
 *
 *     $session = ArrayLegacySession::install(['user' => 'admin', ...]);
 */
final class ArrayLegacySession implements LegacySession
{
    private bool $open = false;

    private bool $destroyed = false;

    private int $regenerations = 0;

    /** @param  array<string, mixed>|null  $data  null means "no session cookie" */
    public function __construct(
        private ?array $data = null,
        private string $sessionId = 'test-session-id',
    ) {}

    /** @param  array<string, mixed>|null  $data */
    public static function install(?array $data = null): self
    {
        $session = new self($data);
        app()->instance(LegacySession::class, $session);

        return $session;
    }

    public function exists(): bool
    {
        return $this->data !== null;
    }

    public function read(): array
    {
        if ($this->data === null) {
            return [];
        }
        $this->open = true;

        return $this->data;
    }

    public function write(array $patch): void
    {
        $this->data ??= [];
        $this->apply($patch);
        $this->open = false;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function establish(array $patch): void
    {
        $this->data ??= [];
        $this->destroyed = false;
        $this->apply($patch);
        $this->sessionId = 'regenerated-'.(++$this->regenerations);
        $this->open = false;
    }

    public function destroy(): void
    {
        $this->data = null;
        $this->destroyed = true;
        $this->open = false;
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    /** @return array<string, mixed>|null */
    public function data(): ?array
    {
        return $this->data;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function assertDestroyed(): void
    {
        Assert::assertTrue($this->destroyed, 'Expected the legacy session to be destroyed.');
    }

    public function assertRegenerated(): void
    {
        Assert::assertGreaterThan(0, $this->regenerations, 'Expected a new legacy session id.');
    }

    /** @param  array<string, mixed>  $patch */
    private function apply(array $patch): void
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($this->data[$key]);
            } else {
                $this->data[$key] = $value;
            }
        }
    }
}
