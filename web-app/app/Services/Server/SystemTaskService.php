<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Services\WebDomains\Step;

/**
 * The system task of E7 batch 3 spec §6: one long server operation at a time
 * (PHP versions, plugin installs, package updates) run in the background by
 * v-start-sys-task.
 */
final class SystemTaskService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * `v-start-sys-task KIND [ARG…]`
     *
     * @param  list<string>  $args
     */
    public function start(string $kind, array $args = []): CommandResult
    {
        return $this->arc->run('v-start-sys-task', [$kind, ...$args], Options::allowFailure());
    }

    /**
     * The start of a system task as a plan step (API v2, E34 batch 6 §5.4,
     * §5.5, §5.7): `v-start-sys-task KIND [ARG…]`, which prints the task ID.
     *
     * @param  list<string>  $args
     */
    public static function step(string $label, string $kind, array $args = []): Step
    {
        return new Step($label, 'v-start-sys-task', [$kind, ...$args]);
    }

    /** The message of a refused start: another task runs, or the command's own error. */
    public static function refusal(CommandResult $result): string
    {
        return $result->exit() === ExitCode::InUse
            ? __('Another task is running. Wait until it has finished.')
            : $result->errorMessage();
    }

    /**
     * The current or last task (`v-list-sys-task 200 json`).
     *
     * @return array{id: string, kind: string, args: string, status: string, exit: string, start: string, end: string, log: string}
     */
    public function task(): array
    {
        $row = $this->arc->json('v-list-sys-task', ['200']);

        return [
            'id' => (string) ($row['ID'] ?? ''),
            'kind' => (string) ($row['KIND'] ?? ''),
            'args' => (string) ($row['ARGS'] ?? ''),
            'status' => (string) ($row['STATUS'] ?? ''),
            'exit' => (string) ($row['EXIT'] ?? ''),
            'start' => (string) ($row['START'] ?? ''),
            'end' => (string) ($row['END'] ?? ''),
            'log' => (string) ($row['LOG'] ?? ''),
        ];
    }

    /** Whether a task runs now. */
    public function running(): bool
    {
        return $this->task()['status'] === 'running';
    }
}
