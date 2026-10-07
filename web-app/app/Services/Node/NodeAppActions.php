<?php

namespace App\Services\Node;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation and env rules of Node.js apps, shared by the session island
 * (NodeAppController) and API v2 (E34 batch 4 spec D47).
 */
final class NodeAppActions
{
    /** A start file or npm:<script> (func/nodeapp.sh). */
    public static function startRule(): string
    {
        return '#^(npm:[A-Za-z0-9][A-Za-z0-9:._-]{0,63}|[A-Za-z0-9_][A-Za-z0-9._/@+-]{0,199})$#';
    }

    /** @return array<string, mixed> */
    public function createRules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
            'start' => ['nullable', 'string', 'max:200', 'regex:'.self::startRule()],
            'version' => ['nullable', 'string', 'regex:/^(default|\d{1,3}\.\d{1,3}\.\d{1,3})$/'],
            'port' => ['nullable', 'string', 'regex:/^(auto|\d{4,5})$/'],
            'cwd' => ['nullable', 'string', 'regex:'.NodeAppService::PATH],
            'instances' => ['nullable', 'integer', 'min:1', 'max:999'],
            'template' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
            'name' => ['nullable', 'string', 'regex:'.NodeAppService::NAME],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated create body
     * @return array{start: string, version: string, port: string, cwd: string, instances: int, template: string, name: string}
     */
    public function createPayload(array $data): array
    {
        return [
            'start' => (string) ($data['start'] ?? ''),
            'version' => (string) ($data['version'] ?? 'default'),
            'port' => (string) ($data['port'] ?? 'auto'),
            'cwd' => (string) ($data['cwd'] ?? 'public_html'),
            'instances' => (int) ($data['instances'] ?? 1),
            'template' => (string) ($data['template'] ?? 'nodejs-proxy'),
            'name' => (string) ($data['name'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function updateRules(): array
    {
        return [
            'start' => ['sometimes', 'required', 'string', 'max:200', 'regex:'.self::startRule()],
            'cwd' => ['required_with:start', 'string', 'regex:'.NodeAppService::PATH],
            'version' => ['sometimes', 'required', 'string', 'regex:/^(default|\d{1,3}\.\d{1,3}\.\d{1,3})$/'],
            'instances' => ['sometimes', 'required', 'integer', 'min:1', 'max:999'],
            'memory_mb' => ['required_with:instances', 'integer', 'min:64', 'max:999999'],
        ];
    }

    /** @return array<string, mixed> */
    public function envSaveRules(): array
    {
        return [
            'vars' => ['present', 'array', 'max:200'],
            'vars.*.key' => ['required', 'string', 'max:128', 'regex:'.NodeAppService::ENV_KEY, Rule::notIn(NodeAppService::RESERVED_ENV), 'distinct'],
            'vars.*.value' => ['present', 'nullable', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
        ];
    }

    /** @return array<string, mixed> */
    public function envApiSaveRules(): array
    {
        return [
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.name' => ['required', 'string', 'max:128', 'regex:'.NodeAppService::ENV_KEY, Rule::notIn(NodeAppService::RESERVED_ENV), 'distinct'],
            'entries.*.value' => ['sometimes', 'nullable', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
        ];
    }

    /**
     * @param  list<array{key: string, value?: string|null}>  $vars
     * @return list<array{key: string, value: string}>
     */
    public function normalizeSessionEnv(array $vars): array
    {
        return array_map(fn (array $var) => [
            'key' => (string) $var['key'],
            'value' => (string) ($var['value'] ?? ''),
        ], $vars);
    }

    /**
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
            $value = array_key_exists('value', $entry)
                ? (string) ($entry['value'] ?? '')
                : ($stored[$key] ?? '');
            $vars[] = ['key' => $key, 'value' => $value];
        }
        $this->assertEnvSize($vars, 'entries');

        return $vars;
    }

    /**
     * @param  list<array{key: string, value: string}>  $vars
     */
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
            'stream' => ['nullable', Rule::in(NodeAppService::STREAMS)],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'after' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function logOffset(Request $request): ?int
    {
        $after = (string) ($request->query('after', ''));
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
     * @return array{lines: list<string>, meta: array{stream: string, next: string|null}}
     */
    public function logResponse(array $snapshot): array
    {
        $next = $snapshot['offset'] < $snapshot['size']
            ? NodeAppLogCursor::encode($snapshot['offset'])
            : null;

        return [
            'lines' => $snapshot['lines'],
            'meta' => [
                'stream' => $snapshot['stream'],
                'next' => $next,
            ],
        ];
    }
}
