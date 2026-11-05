<?php

namespace App\Services\Tasks;

/**
 * One task as API v2 exposes it (E34 batch 3 spec §5, D19; since E15 a job,
 * with its object, actor and the job it retries).
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
        public readonly ?string $object = null,
        public readonly ?string $actor = null,
        public readonly ?string $retryOf = null,
        /** The job type (E15 §3.4); not part of the API schema */
        public readonly ?string $type = null,
        /** The hosting user of the job, `system` for server jobs; not part of the API schema */
        public readonly ?string $user = null,
        /** Whether the type can be retried; not part of the API schema */
        public readonly bool $retryable = false,
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
            'object' => $this->object,
            'actor' => $this->actor,
            'retry_of' => $this->retryOf,
        ];
    }

    public function finished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled'], true);
    }
}
