<?php

namespace App\Services\Tasks;

/**
 * One task as API v2 exposes it (E34 batch 3 spec §5, D19).
 */
final class TaskView
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly ?string $error,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $queuedAt,
        public readonly string $log,
        public readonly ?string $args,
        public readonly ?string $resource,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'error' => $this->error,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'queued_at' => $this->queuedAt,
            'log' => $this->log,
            'args' => $this->args,
            'resource' => $this->resource,
        ];
    }
}
