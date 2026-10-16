<?php

namespace App\Services\AppProcesses;

/**
 * The current or last one-off task of a user's app processes
 * (`v-list-app-process-task USER json`, E42 spec §6.2).
 */
final class AppProcessTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $domain,
        public readonly string $name,
        public readonly string $task,
        public readonly string $status,
        public readonly string $exit,
        public readonly string $start,
        public readonly string $end,
    ) {}

    /** @param  array<string, mixed>|null  $data  the TASK object, null when there was none */
    public static function from(?array $data): ?self
    {
        if ($data === null || ($data['ID'] ?? '') === '') {
            return null;
        }
        $value = fn (string $key) => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            id: $value('ID'),
            domain: $value('DOMAIN'),
            name: $value('NAME'),
            task: $value('TASK'),
            status: $value('STATUS'),
            exit: $value('EXIT'),
            start: $value('START'),
            end: $value('END'),
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
            'domain' => $this->domain,
            'name' => $this->name,
            'task' => $this->task,
            'status' => $this->status,
            'running' => $this->running(),
            'exit' => $this->exit,
            'start' => $this->start,
            'end' => $this->end,
        ];
    }
}
