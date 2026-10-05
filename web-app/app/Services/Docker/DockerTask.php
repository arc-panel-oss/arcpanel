<?php

namespace App\Services\Docker;

/**
 * The current or last task of a Docker app (`v-list-docker-app-task USER
 * NAME OFFSET json`) or of the engine (`v-list-sys-docker-task OFFSET
 * json`), E4 spec §5.5: the record and the output after a byte offset.
 */
final class DockerTask
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
        public readonly int $offset,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $value = fn (string $key) => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            id: $value('ID'),
            action: $value('ACTION'),
            args: $value('ARGS'),
            status: $value('STATUS'),
            exit: $value('EXIT'),
            start: $value('START'),
            end: $value('END'),
            log: $value('LOG'),
            offset: (int) $value('OFFSET'),
        );
    }

    public function running(): bool
    {
        return $this->status === 'running';
    }

    /** @return array<string, string|int|bool> */
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
            'offset' => $this->offset,
        ];
    }
}
