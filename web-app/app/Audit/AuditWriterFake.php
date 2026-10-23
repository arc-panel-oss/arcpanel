<?php

namespace App\Audit;

/**
 * Test double for AuditWriter: keeps the events, or fails every write after
 * failWith(). Bound by the test case for every test, so a test reads what a
 * request recorded with `AuditWriterFake::events()`.
 */
final class AuditWriterFake implements AuditWriter
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private ?string $failure = null;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(AuditWriter::class, $fake);

        return $fake;
    }

    public function write(array $event): void
    {
        if ($this->failure !== null) {
            throw new AuditWriteFailed($this->failure);
        }
        $this->events[] = $event;
    }

    public function failWith(string $message): self
    {
        $this->failure = $message;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function events(?string $action = null): array
    {
        return array_values(array_filter($this->events, fn (array $event) => $action === null || $event['action'] === $action));
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
