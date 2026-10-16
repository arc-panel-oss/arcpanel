<?php

namespace App\Services\AppProcesses;

use App\Services\Node\NodeAppLogCursor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation and env rules of app processes, shared by the session island
 * (AppProcessController) and REST API v2 (E42 spec §8).
 */
final class AppProcessActions
{
    /** A command line without control characters (appproc_check_start). */
    public const START = '/^[^\x00-\x1f\x7f]{1,2048}$/';

    /** @return array<string, mixed> */
    public function createRules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
            'name' => ['required', 'string', 'regex:'.AppProcessService::NAME],
            'preset' => ['required', 'string', 'regex:'.AppProcessService::PRESET],
            'start' => ['nullable', 'required_if:preset,custom', 'string', 'max:2048', 'regex:'.self::START],
            'cwd' => ['nullable', 'string', 'regex:'.AppProcessService::PATH],
            'memory_mb' => ['nullable', 'integer', 'min:32', 'max:999999'],
            'cpus' => ['nullable', 'string', 'regex:'.AppProcessService::CPUS],
            'kind' => ['nullable', Rule::in(['web', 'worker'])],
            'template' => ['nullable', 'required_if:kind,web', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated create body
     * @return array{name: string, preset: string, start: string, cwd: string, memory: string, cpus: string, kind: string, template: string}
     */
    public function createPayload(array $data): array
    {
        $custom = ($data['preset'] ?? '') === 'custom';

        return [
            'name' => (string) $data['name'],
            'preset' => (string) $data['preset'],
            'start' => (string) ($data['start'] ?? ''),
            'cwd' => (string) ($data['cwd'] ?? 'public_html'),
            'memory' => isset($data['memory_mb']) ? (string) (int) $data['memory_mb'] : '',
            'cpus' => (string) ($data['cpus'] ?? '1'),
            'kind' => $custom ? (string) ($data['kind'] ?? 'worker') : '',
            'template' => $custom && ($data['kind'] ?? 'worker') === 'web' ? (string) ($data['template'] ?? '') : '',
        ];
    }

    /** @return array<string, mixed> */
    public function updateRules(): array
    {
        return [
            'start' => ['sometimes', 'required', 'string', 'max:2048', 'regex:'.self::START],
            'cwd' => ['required_with:start', 'string', 'regex:'.AppProcessService::PATH],
            'memory_mb' => ['sometimes', 'required', 'integer', 'min:32', 'max:999999'],
            'cpus' => ['required_with:memory_mb', 'string', 'regex:'.AppProcessService::CPUS],
        ];
    }

    /** @return array<string, mixed> */
    public function envSaveRules(): array
    {
        return [
            'vars' => ['present', 'array', 'max:200'],
            'vars.*.key' => ['required', 'string', 'max:128', 'regex:'.AppProcessService::ENV_KEY, Rule::notIn(AppProcessService::RESERVED_ENV), 'distinct'],
            'vars.*.value' => ['present', 'nullable', 'string', 'max:4096', 'not_regex:/[\x00-\x1f\x7f]/'],
        ];
    }

    /** @return array<string, mixed> */
    public function envApiSaveRules(): array
    {
        return [
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.name' => ['required', 'string', 'max:128', 'regex:'.AppProcessService::ENV_KEY, Rule::notIn(AppProcessService::RESERVED_ENV), 'distinct'],
            'entries.*.value' => ['sometimes', 'nullable', 'string', 'max:4096', 'not_regex:/[\x00-\x1f\x7f]/'],
        ];
    }

    /** @return array<string, mixed> */
    public function taskRules(): array
    {
        return [
            'task' => ['required', Rule::in(AppProcessService::TASKS)],
            'command' => ['nullable', 'required_if:task,custom', 'string', 'max:2048', 'regex:'.self::START],
        ];
    }

    /**
     * @param  list<array{key: string, value?: string|null}>  $vars
     * @return list<array{key: string, value: string}>
     */
    public function normalizeSessionEnv(array $vars): array
    {
        $vars = array_map(fn (array $var) => [
            'key' => (string) $var['key'],
            'value' => (string) ($var['value'] ?? ''),
        ], $vars);
        $this->assertEnvSize($vars);

        return $vars;
    }

    /**
     * Entries of the API body over the stored values: an entry without a
     * value keeps the stored one, an omitted name is removed.
     *
     * @param  list<array{name: string, value?: string|null}>  $entries
     * @param  list<array{key: string, value: string}>  $current
     * @return list<array{key: string, value: string}>
     */
    public function mergeEnvEntries(array $entries, array $current): array
    {
        $stored = [];
        foreach ($current as $var) {
            $stored[$var['key']] = $var['value'];
        }
        $vars = [];
        foreach ($entries as $entry) {
            $key = (string) $entry['name'];
            $vars[] = ['key' => $key, 'value' => array_key_exists('value', $entry) ? (string) ($entry['value'] ?? '') : ($stored[$key] ?? '')];
        }
        $this->assertEnvSize($vars, 'entries');

        return $vars;
    }

    /** @param  list<array{key: string, value: string}>  $vars */
    public function assertEnvSize(array $vars, string $field = 'vars'): void
    {
        if (strlen(implode("\n", array_map(fn ($var) => $var['key'].'='.$var['value'], $vars))) > 65000) {
            throw ValidationException::withMessages([$field => __('The environment is larger than 64 KB.')]);
        }
    }

    /**
     * @param  list<array{key: string, value: string}>  $vars
     * @return list<array{name: string, set: bool, value?: string}>
     */
    public function envEntries(array $vars, bool $reveal): array
    {
        $entries = [];
        foreach ($vars as $var) {
            $row = ['name' => $var['key'], 'set' => $var['value'] !== ''];
            if ($reveal) {
                $row['value'] = $var['value'];
            }
            $entries[] = $row;
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    public function logRules(): array
    {
        return [
            'stream' => ['nullable', Rule::in(AppProcessService::STREAMS)],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'after' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function logOffset(Request $request): ?int
    {
        $after = (string) $request->query('after', '');
        if ($after === '') {
            return null;
        }
        $offset = NodeAppLogCursor::decode($after);
        if ($offset === null) {
            throw ValidationException::withMessages(['after' => __('The log cursor is invalid.')]);
        }

        return $offset;
    }

    /**
     * @param  array{stream: string, size: int, offset: int, lines: list<string>}  $snapshot
     * @return array{lines: list<string>, meta: array{stream: string, next: string}}
     */
    public function logResponse(array $snapshot): array
    {
        return [
            'lines' => $snapshot['lines'],
            'meta' => [
                'stream' => $snapshot['stream'],
                'next' => NodeAppLogCursor::encode($snapshot['offset']),
            ],
        ];
    }
}
