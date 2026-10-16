<?php

namespace App\Services\AppProcesses;

use App\Arc\ArcCommand;
use App\Arc\Secret;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainService;

/**
 * App processes of a user (E42 spec §8): one method per command through
 * ArcCommand, and a plan step per change for REST API v2. AppProcessPolicy
 * decides who may do what; the commands check the package keys again on
 * the server.
 */
final class AppProcessService
{
    /** Process names (func/appprocess.sh appproc_name_valid) */
    public const NAME = '/^[a-z][a-z0-9-]{0,31}$/';

    /** Preset names of install/common/app-processes/presets.conf */
    public const PRESET = '/^[a-z0-9-]{1,40}$/';

    /**
     * A relative path without "..", or "." (appproc_relpath_valid); "/"
     * delimiters: the API document keeps any other one in its pattern.
     */
    public const PATH = '/^(\.|[A-Za-z0-9_][A-Za-z0-9._\/@+-]{0,199})$/';

    /** CPUs of a process, like 1 or 0.5 */
    public const CPUS = '/^\d{1,2}(\.\d{1,2})?$/';

    /** Environment keys; the runner sets the reserved ones */
    public const ENV_KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public const RESERVED_ENV = ['PORT', 'HOST', 'PATH', 'HOME', 'USER', 'LOGNAME'];

    public const ACTIONS = ['start', 'stop', 'restart', 'reload'];

    public const STREAMS = ['process', 'task'];

    public const TASKS = ['composer-install', 'migrate', 'pip-install', 'bundle-install', 'custom'];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly WebDomainService $domains,
        private readonly PlanRunner $plans,
    ) {}

    public function processes(string $user): AppProcesses
    {
        return AppProcesses::from($this->arc->json('v-list-app-processes', [$user]));
    }

    public function show(string $user, string $domain, string $name): AppProcess
    {
        return AppProcess::from($this->arc->json('v-list-app-process', [$user, $domain, $name]));
    }

    /**
     * The preset catalog; with a domain, the detected presets and missing
     * runtimes of CWD in it.
     *
     * @return list<array<string, string>>
     */
    public function presets(?string $user = null, ?string $domain = null, string $cwd = 'public_html'): array
    {
        $args = $user !== null && $domain !== null ? [$user, $domain, $cwd] : [];
        $presets = [];
        foreach ((array) ($this->arc->json('v-list-app-process-presets', $args)['PRESETS'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['NAME'] ?? null)) {
                continue;
            }
            $value = fn (string $key) => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
            $presets[] = [
                'name' => $value('NAME'),
                'title' => $value('TITLE'),
                'kind' => $value('KIND'),
                'template' => $value('TEMPLATE'),
                'runtime' => $value('RUNTIME'),
                'start' => $value('START'),
                'reload' => $value('RELOAD'),
                'detected' => $value('DETECTED'),
                'runtimeMissing' => $value('RUNTIME_MISSING'),
                'templateAvailable' => $value('TEMPLATE_AVAILABLE'),
            ];
        }

        return $presets;
    }

    /**
     * A log of the process: the last LINES lines, or what was written after
     * OFFSET.
     *
     * @return array{stream: string, size: int, offset: int, lines: list<string>}
     */
    public function logs(string $user, string $domain, string $name, string $stream, ?int $offset, int $lines = 200): array
    {
        $data = $this->arc->json('v-list-app-process-logs', [$user, $domain, $name, $stream, $offset === null ? '' : (string) $offset, (string) $lines]);

        return [
            'stream' => (string) ($data['STREAM'] ?? $stream),
            'size' => (int) ($data['SIZE'] ?? 0),
            'offset' => (int) ($data['OFFSET'] ?? 0),
            'lines' => array_values(array_map('strval', array_filter((array) ($data['LINES'] ?? []), 'is_scalar'))),
        ];
    }

    /** @return list<array{key: string, value: string}> */
    public function env(string $user, string $domain, string $name): array
    {
        $vars = [];
        foreach ((array) ($this->arc->json('v-list-app-process-env', [$user, $domain, $name])['VARS'] ?? []) as $row) {
            if (is_array($row) && is_string($row['KEY'] ?? null)) {
                $vars[] = ['key' => $row['KEY'], 'value' => is_scalar($row['VALUE'] ?? null) ? (string) $row['VALUE'] : ''];
            }
        }

        return $vars;
    }

    public function task(string $user): ?AppProcessTask
    {
        $data = $this->arc->json('v-list-app-process-task', [$user]);

        return AppProcessTask::from(is_array($data['TASK'] ?? null) ? $data['TASK'] : null);
    }

    /** @param  array{name: string, preset: string, start: string, cwd: string, memory: string, cpus: string, kind: string, template: string}  $process */
    public function create(string $user, string $domain, array $process): void
    {
        $this->arc->run('v-add-app-process', $this->createArgs($user, $domain, $process));
    }

    public function delete(string $user, string $domain, string $name): void
    {
        $this->arc->run('v-delete-app-process', [$user, $domain, $name]);
    }

    public function action(string $user, string $domain, string $name, string $action): void
    {
        $this->arc->run('v-'.$action.'-app-process', [$user, $domain, $name]);
    }

    public function changeStart(string $user, string $domain, string $name, string $start, string $cwd): void
    {
        $this->arc->run('v-change-app-process-start', [$user, $domain, $name, $start, $cwd]);
    }

    public function changeLimits(string $user, string $domain, string $name, int $memory, string $cpus): void
    {
        $this->arc->run('v-change-app-process-limits', [$user, $domain, $name, (string) $memory, $cpus]);
    }

    /**
     * Replace the environment; the values travel in a temp file only.
     *
     * @param  list<array{key: string, value: string}>  $vars
     */
    public function saveEnv(string $user, string $domain, string $name, array $vars): void
    {
        $this->arc->run('v-change-app-process-env', [$user, $domain, $name, Secret::file(self::envContent($vars))]);
    }

    /** Start a one-off task; returns its id. */
    public function startTask(string $user, string $domain, string $name, string $task, string $command = ''): string
    {
        $args = [$user, $domain, $name, $task];
        if ($task === 'custom') {
            $args[] = $command;
        }

        return trim($this->arc->run('v-start-app-process-task', $args)->stdout);
    }

    /** @param  array{name: string, preset: string, start: string, cwd: string, memory: string, cpus: string, kind: string, template: string}  $process */
    public function planCreate(string $user, string $domain, array $process): Step
    {
        return new Step(__('Create the app process'), 'v-add-app-process', $this->createArgs($user, $domain, $process));
    }

    public function planDelete(string $user, string $domain, string $name): Step
    {
        return new Step(__('Delete the app process'), 'v-delete-app-process', [$user, $domain, $name]);
    }

    public function planAction(string $user, string $domain, string $name, string $action): Step
    {
        return new Step(__('Run :action on the app process', ['action' => $action]), 'v-'.$action.'-app-process', [$user, $domain, $name]);
    }

    public function planChangeStart(string $user, string $domain, string $name, string $start, string $cwd): Step
    {
        return new Step(__('Change the command'), 'v-change-app-process-start', [$user, $domain, $name, $start, $cwd]);
    }

    public function planChangeLimits(string $user, string $domain, string $name, int $memory, string $cpus): Step
    {
        return new Step(__('Change the limits'), 'v-change-app-process-limits', [$user, $domain, $name, (string) $memory, $cpus]);
    }

    /** @param  list<array{key: string, value: string}>  $vars */
    public function planSaveEnv(string $user, string $domain, string $name, array $vars): Step
    {
        return new Step(__('Update the environment'), 'v-change-app-process-env', [$user, $domain, $name, Secret::file(self::envContent($vars))]);
    }

    public function planStartTask(string $user, string $domain, string $name, string $task, string $command = ''): Step
    {
        $args = [$user, $domain, $name, $task];
        if ($task === 'custom') {
            $args[] = $command;
        }

        return new Step(__('Start the :task task', ['task' => $task]), 'v-start-app-process-task', $args);
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        return $this->plans->apply($steps);
    }

    /**
     * Web domains of $user that can get a process (not suspended), sorted.
     *
     * @return list<string>
     */
    public function domains(string $user): array
    {
        $domains = [];
        foreach ($this->domains->forUser($user) as $domain => $record) {
            if (! $record->suspended) {
                $domains[] = (string) $domain;
            }
        }
        sort($domains);

        return $domains;
    }

    /**
     * @param  array{name: string, preset: string, start: string, cwd: string, memory: string, cpus: string, kind: string, template: string}  $process
     * @return list<string>
     */
    private function createArgs(string $user, string $domain, array $process): array
    {
        $args = [$user, $domain, $process['name'], $process['preset'], $process['start'], $process['cwd'], $process['memory'], $process['cpus']];
        if ($process['preset'] === 'custom') {
            $args[] = $process['kind'];
            $args[] = $process['template'];
        }

        return $args;
    }

    /** @param  list<array{key: string, value: string}>  $vars */
    private static function envContent(array $vars): string
    {
        $content = '';
        foreach ($vars as $var) {
            $content .= $var['key'].'='.str_replace(["\r", "\n"], '', $var['value'])."\n";
        }

        return $content;
    }
}
