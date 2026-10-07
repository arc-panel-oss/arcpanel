<?php

namespace App\Services\Docker;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation, catalog filtering and env rules of Docker apps, shared by the
 * session island (DockerAppController) and API v2 (E34 batch 4 spec D47).
 */
final class DockerAppActions
{
    /** @return array<string, mixed> */
    public function createRules(): array
    {
        return [
            'slug' => ['required', 'string', 'regex:'.DockerCatalog::SLUG],
            'name' => ['required', 'string', 'regex:'.DockerAppService::NAME],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
            'version' => ['nullable', 'string', 'regex:'.DockerCatalog::VERSION],
            'vars' => ['nullable', 'array', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function taskRules(): array
    {
        return [
            'action' => ['required', Rule::in(DockerAppService::TASKS)],
            'version' => ['nullable', 'string', 'regex:'.DockerCatalog::VERSION],
        ];
    }

    /** @return array<string, mixed> */
    public function limitsRules(): array
    {
        return [
            'cpus' => ['required', 'string', 'regex:'.DockerAppService::CPUS],
            'memory_mb' => ['required', 'integer', 'min:32', 'max:9999999'],
        ];
    }

    /** @return array<string, mixed> */
    public function domainRules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
        ];
    }

    /** @return array<string, mixed> */
    public function envSaveRules(): array
    {
        return [
            'vars' => ['present', 'array', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function logRules(): array
    {
        return [
            'service' => ['nullable', 'string', 'regex:/^(all|[a-z0-9][a-z0-9_.-]{0,62})$/'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'after' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @param  array<mixed>  $vars
     * @return array<string, string>
     */
    public function checkedVars(array $vars, bool $dropEmpty): array
    {
        $checked = [];
        $errors = [];
        foreach ($vars as $key => $value) {
            $value = $value === null ? '' : $value;
            if (! is_string($key) || preg_match(DockerAppService::VARIABLE, $key) !== 1 || str_starts_with($key, 'ARC_')) {
                $errors['vars'] = __('Unknown variable.');

                continue;
            }
            if (! is_scalar($value) || strlen((string) $value) > 1024 || preg_match(DockerAppService::FORBIDDEN_VALUE, (string) $value) === 1) {
                $errors['vars.'.$key] = __('At most 1024 characters, without quotes, $, ` or \\.');

                continue;
            }
            if ($dropEmpty && (string) $value === '') {
                continue;
            }
            $checked[$key] = (string) $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /**
     * @param  list<array<string, mixed>>  $apps  catalog summaries from DockerCatalog
     * @return list<array<string, mixed>>
     */
    public function visibleCatalog(array $apps, bool $admin): array
    {
        return array_values(array_filter($apps, fn (array $app) => $admin || ! ($app['adminOnly'] ?? false)));
    }

    public function catalogAppVisible(array $app, bool $admin): bool
    {
        return $admin || ! ($app['adminOnly'] ?? false);
    }

    /**
     * @param  list<array<string, mixed>>  $variables  from DockerCatalog::app()
     * @return list<array<string, mixed>>
     */
    public function catalogVariablesForApi(array $variables): array
    {
        return array_map(function (array $variable): array {
            $default = (string) ($variable['default'] ?? '');
            if (($variable['random'] ?? false) && $default !== '') {
                $default = 'generated';
            }

            return [
                'name' => (string) $variable['name'],
                'label' => (string) ($variable['label'] ?? $variable['name']),
                'type' => (string) ($variable['type'] ?? 'string'),
                'options' => array_values(array_map('strval', (array) ($variable['options'] ?? []))),
                'default' => $default,
                'required' => (bool) ($variable['required'] ?? false),
                'immutable' => (bool) ($variable['immutable'] ?? false),
                'secret' => (bool) ($variable['secret'] ?? false),
                'pattern' => (string) ($variable['pattern'] ?? ''),
            ];
        }, $variables);
    }

    /**
     * @param  list<array<string, mixed>>  $vars  from DockerAppService::env()
     * @return list<array<string, mixed>>
     */
    public function envEntries(array $vars): array
    {
        $entries = [];
        foreach ($vars as $var) {
            $row = [
                'name' => (string) $var['name'],
                'label' => (string) ($var['label'] ?? $var['name']),
                'type' => (string) ($var['type'] ?? 'string'),
                'options' => array_values(array_map('strval', (array) ($var['options'] ?? []))),
                'secret' => (bool) ($var['secret'] ?? false),
                'immutable' => (bool) ($var['immutable'] ?? false),
                'required' => (bool) ($var['required'] ?? false),
                'pattern' => (string) ($var['pattern'] ?? ''),
                'set' => (bool) ($var['set'] ?? false),
            ];
            if (! $row['secret'] && is_scalar($var['value'] ?? null) && (string) $var['value'] !== '') {
                $row['value'] = (string) $var['value'];
            }
            $entries[] = $row;
        }

        return $entries;
    }

    public function logSince(Request $request): ?string
    {
        $after = (string) ($request->query('after', ''));
        if ($after === '') {
            return null;
        }
        $since = DockerAppLogCursor::decode($after);
        if ($since === null) {
            throw ValidationException::withMessages(['after' => __('The log cursor is invalid.')]);
        }

        return $since;
    }

    /**
     * @param  array{lines: list<array{time: string, service: string, text: string}>, next: string}  $snapshot
     * @return array{lines: list<array{time: string, service: string, text: string}>, meta: array{next: string|null}}
     */
    public function logResponse(array $snapshot): array
    {
        $next = $snapshot['next'] !== '' ? DockerAppLogCursor::encode($snapshot['next']) : null;

        return [
            'lines' => $snapshot['lines'],
            'meta' => ['next' => $next],
        ];
    }
}
