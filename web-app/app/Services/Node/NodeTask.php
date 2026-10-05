<?php

namespace App\Services\Node;

/**
 * The current or last background Node.js task of a user
 * (`v-list-user-node-task USER LINES json`, E13 spec §5.6).
 */
final class NodeTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly string $args,
        public readonly string $status,
        public readonly string $exit,
        public readonly string $start,
        public readonly string $end,
        public readonly string $log,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        return new self(
            id: (string) ($data['ID'] ?? ''),
            action: (string) ($data['ACTION'] ?? ''),
            args: (string) ($data['ARGS'] ?? ''),
            status: (string) ($data['STATUS'] ?? ''),
            exit: (string) ($data['EXIT'] ?? ''),
            start: (string) ($data['START'] ?? ''),
            end: (string) ($data['END'] ?? ''),
            log: (string) ($data['LOG'] ?? ''),
        );
    }

    public function running(): bool
    {
        return $this->status === 'running';
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'args' => $this->args,
            'status' => $this->status,
            'running' => $this->running(),
            'exit' => $this->exit,
            'start' => $this->start,
            'end' => $this->end,
            'log' => $this->log,
        ];
    }
}
