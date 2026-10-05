<?php

namespace App\Services\Docker;

use App\Arc\ArcCommand;
use App\Arc\Secret;
use App\Services\Node\NodeAppService;
use App\Services\WebDomains\WebDomainService;

/**
 * Docker apps of a user (E4 spec §6.1): one method per command through
 * ArcCommand. Installing, updating, rolling back and dumping start a task
 * of the app; variables travel in Secret temp files only. DockerPolicy
 * decides who may do what and the commands check the package keys again.
 */
final class DockerAppService
{
    /** App instance names (func/docker.sh dockerapp_name_valid) */
    public const NAME = '/^[a-z][a-z0-9-]{0,30}[a-z0-9]$/';

    /** Variable names of the manifests */
    public const VARIABLE = '/^[A-Z][A-Z0-9_]{0,63}$/';

    /** What a variable value may not contain (docker_var_check) */
    public const FORBIDDEN_VALUE = '/[\'"$`\\\\\r\n\x00]/';

    /** Compose services */
    public const SERVICE = '/^[a-z0-9][a-z0-9_.-]{0,62}$/';

    /** CPUs per service, like 0.5 */
    public const CPUS = '/^\d{1,3}(\.\d{1,2})?$/';

    /** --since cursors of v-list-docker-app-logs */
    public const SINCE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?Z$/';

    public const ACTIONS = ['start', 'stop', 'restart'];

    public const TASKS = ['install', 'update', 'rollback', 'snapshot'];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly WebDomainService $domains,
        private readonly NodeAppService $nodeApps,
    ) {}

    public function apps(string $user): DockerApps
    {
        return DockerApps::from($this->arc->json('v-list-docker-apps', [$user]));
    }

    public function show(string $user, string $name): DockerApp
    {
        return DockerApp::from($this->arc->json('v-list-docker-app', [$user, $name]));
    }

    /**
     * Install $slug as the app $name; returns the ID of the install task.
     *
     * @param  array<string, string>  $vars  variable values; empty ones take the default
     */
    public function install(string $user, string $slug, string $name, string $domain, array $vars, string $version = ''): string
    {
        $result = $this->arc->run('v-add-docker-app', [$user, $slug, $name, $domain, self::varsFile($vars), $version]);

        return self::lastLine($result->stdout);
    }

    public function delete(string $user, string $name, bool $keepData): void
    {
        $this->arc->run('v-delete-docker-app', $keepData ? [$user, $name, 'keep-data'] : [$user, $name]);
    }

    public function action(string $user, string $name, string $action): void
    {
        $this->arc->run('v-'.$action.'-docker-app', [$user, $name]);
    }

    /**
     * Variables of the app: name, label, type, options, flags and the value
     * (empty for secrets).
     *
     * @return list<array<string, mixed>>
     */
    public function env(string $user, string $name): array
    {
        $vars = [];
        foreach ((array) ($this->arc->json('v-list-docker-app-env', [$user, $name])['VARIABLES'] ?? []) as $row) {
            if (is_array($row) && is_string($row['NAME'] ?? null)) {
                $vars[] = [
                    'name' => $row['NAME'],
                    'label' => (string) ($row['LABEL'] ?? $row['NAME']),
                    'type' => (string) ($row['TYPE'] ?? 'string'),
                    'options' => array_values(array_map('strval', (array) ($row['OPTIONS'] ?? []))),
                    'secret' => ($row['SECRET'] ?? '') === 'yes',
                    'immutable' => ($row['IMMUTABLE'] ?? '') === 'yes',
                    'required' => ($row['REQUIRED'] ?? '') === 'yes',
                    'pattern' => (string) ($row['PATTERN'] ?? ''),
                    'set' => ($row['SET'] ?? '') === 'yes',
                    'value' => is_scalar($row['VALUE'] ?? null) ? (string) $row['VALUE'] : '',
                ];
            }
        }

        return $vars;
    }

    /** @param  array<string, string>  $vars  new values; an empty secret keeps its value */
    public function saveEnv(string $user, string $name, array $vars): void
    {
        $this->arc->run('v-change-docker-app-env', [$user, $name, self::varsFile($vars)]);
    }

    public function limits(string $user, string $name, string $cpus, int $memory): void
    {
        $this->arc->run('v-change-docker-app-limits', [$user, $name, $cpus, (string) $memory]);
    }

    public function changeDomain(string $user, string $name, string $domain): void
    {
        $this->arc->run('v-change-docker-app-domain', [$user, $name, $domain]);
    }

    /**
     * Container logs: the last $lines lines, or the lines after $since.
     *
     * @return array{lines: list<array{time: string, service: string, text: string}>, next: string}
     */
    public function logs(string $user, string $name, string $service, ?string $since, int $lines = 200): array
    {
        $data = $this->arc->json('v-list-docker-app-logs', [$user, $name, $service, $since ?? '', (string) $lines]);
        $rows = [];
        foreach ((array) ($data['LINES'] ?? []) as $line) {
            if (is_array($line)) {
                $rows[] = [
                    'time' => (string) ($line['TIME'] ?? ''),
                    'service' => (string) ($line['SERVICE'] ?? ''),
                    'text' => (string) ($line['TEXT'] ?? ''),
                ];
            }
        }

        return ['lines' => $rows, 'next' => (string) ($data['NEXT'] ?? '')];
    }

    public function task(string $user, string $name, int $offset = 0): DockerTask
    {
        return DockerTask::from($this->arc->json('v-list-docker-app-task', [$user, $name, (string) $offset]));
    }

    /** Start install, update, rollback or snapshot; returns the task ID. */
    public function startTask(string $user, string $name, string $action, string $version = ''): string
    {
        $args = [$user, $name, $action];
        if ($action === 'update' && $version !== '') {
            $args[] = $version;
        }

        return self::lastLine($this->arc->run('v-start-docker-app-task', $args)->stdout);
    }

    /**
     * Web domains of $user that can get an app: not suspended, without a
     * Docker or Node.js app; sorted.
     *
     * @return list<string>
     */
    public function freeDomains(string $user, DockerApps $apps): array
    {
        $used = array_flip($apps->domains());
        $node = $this->nodeApps->apps($user);
        $free = [];
        foreach ($this->domains->forUser($user) as $domain => $record) {
            if (! isset($used[$domain]) && $node->find($domain) === null && ! $record->suspended) {
                $free[] = $domain;
            }
        }
        sort($free);

        return $free;
    }

    /** @param  array<string, string>  $vars */
    private static function varsFile(array $vars): Secret
    {
        $content = '';
        foreach ($vars as $key => $value) {
            $content .= $key.'='.str_replace(["\r", "\n"], '', $value)."\n";
        }

        return Secret::file($content);
    }

    private static function lastLine(string $stdout): string
    {
        $lines = preg_split('/\R/', trim($stdout)) ?: [];

        return trim((string) end($lines));
    }
}
