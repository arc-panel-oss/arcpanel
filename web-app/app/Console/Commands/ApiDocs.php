<?php

namespace App\Console\Commands;

use App\Api\ErrorCode;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;

/**
 * Writes docs/api/v2/openapi.json (E34 spec D14, §7): Scramble infers the
 * operations from the routes, FormRequests and Resources of API v2; this
 * command adds what the code cannot tell — the servers, the bearer scheme,
 * the one error body and its codes, the dry run, the headers of every
 * answer — and checks that every operation has a unique camelCase
 * operationId. With --check it fails when the committed file differs (CI).
 */
#[Signature('arcpanel:api-docs {--path= : File to write (default: ../docs/api/v2/openapi.json)} {--check : Fail when the file differs instead of writing it}')]
#[Description('Generate the OpenAPI document of REST API v2')]
final class ApiDocs extends Command
{
    private const ERROR_STATUSES = [
        '401' => ['Unauthenticated', 'The access key is missing, unknown or wrong (`E_UNAUTHENTICATED`).'],
        '403' => ['Forbidden', 'The API is off for this key (`E_API_DISABLED`), the role or a permission set refuses the call (`E_FORBIDDEN`), or the owner is suspended (`E_SUSPENDED`).'],
        '404' => ['NotFound', 'No such object in the scope of the key (`E_NOTEXIST`).'],
        '409' => ['Conflict', 'The object exists or is in use (`E_EXISTS`, `E_INUSE`).'],
        '422' => ['ValidationFailed', 'The request is invalid (`E_VALIDATION` with `fields`, or a command refused an argument).'],
        '429' => ['TooManyRequests', 'Too many requests (`E_RATE_LIMIT`, or a package limit `E_LIMIT`); `Retry-After` names the seconds to wait.'],
        '500' => ['ServerError', 'A command failed on the server.'],
        '503' => ['Unavailable', 'The service is disabled or the server is too busy (`E_DISABLED`, `E_LA`).'],
    ];

    private const DESCRIPTION = <<<'MD'
        Resource API of an ArcPanel server, served by the panel at `/api/v2/` (port 8083 by default). Every call acts as the owner of the access key, with the role and the reach that user has in the panel: objects outside its reach answer `404`, refused actions `403`.

        **Authentication.** `Authorization: Bearer ACCESS_KEY_ID:SECRET_ACCESS_KEY`, an access key of the panel (Account → Access keys). The API must be on (`API_SYSTEM` 1 for the administrator's keys, 2 for every key) and accept the client's address (`API_ALLOWED_IP`). A key with permission sets may only run their commands: `v2-read` allows the read endpoints, `v2-write` every endpoint. Both sets are refused by API v1. `GET /me` describes the key.

        **Dry run.** Every write accepts `?dry_run=1`: the request is validated, authorized and checked against the permission sets, and the answer lists the commands it would run, without running any.

        **Errors.** Every error answers `{"error": {"code": "E_…", "message": "…"}}`; `code` is one of `ErrorCode`. Validation errors add `fields`, refused permission sets add `commands`, a failed step of a change adds `step` and the steps that ran before it (`applied`), which stay applied.

        **Headers.** Every answer carries `ArcPanel-Version` and `X-Request-Id` (the client's own value, or a new one). `429` carries `Retry-After`; `201` and `202` carry `Location`. Each key may make 60 requests a minute; ten failed authentications a minute block the client's address for a minute.

        **Compatibility.** Within v2 the document only grows: new operations, new optional fields, new enum values. Clients should ignore unknown fields.
        MD;

    public function handle(): int
    {
        if (! class_exists(Scramble::class)) {
            $this->error('dedoc/scramble is not installed (composer install with the dev dependencies).');

            return self::FAILURE;
        }
        $path = $this->option('path') ?: base_path('../docs/api/v2/openapi.json');

        $spec = $this->document(app(Generator::class)->generate(Scramble::getGeneratorConfig('default'))->spec());
        $problems = $this->problems($spec);
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if ($this->option('check')) {
            $current = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : '';
            if ($current !== $json) {
                $this->error("{$path} is out of date: run `php artisan arcpanel:api-docs`.");

                return self::FAILURE;
            }
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $json);
        $this->info(count($this->operations($spec))." operations written to {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function document(array $spec): array
    {
        $spec['info'] = ['title' => 'ArcPanel REST API v2', 'version' => '2', 'description' => self::DESCRIPTION];
        $spec['servers'] = [[
            'url' => 'https://{host}:{port}/api/v2',
            'description' => 'An ArcPanel server',
            'variables' => ['host' => ['default' => 'panel.example.com'], 'port' => ['default' => '8083']],
        ]];
        $spec['security'] = [['accessKey' => []]];

        $components = $spec['components'] ?? [];
        $components['securitySchemes'] = ['accessKey' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'ACCESS_KEY_ID:SECRET_ACCESS_KEY',
            'description' => 'An access key of the panel and its secret, joined by a colon.',
        ]];
        $components['schemas'] = self::withNullableObjects(array_merge($components['schemas'] ?? [], $this->schemas()));
        $components['headers'] = $this->headers();
        $components['responses'] = $this->errorResponses();
        ksort($components['schemas']);
        $spec['components'] = $components;

        $statuses = $this->declaredStatuses();
        foreach ($spec['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation) && in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $spec['paths'][$path][$method] = $this->operation($path, $method, $operation, $statuses[$method.' '.$path] ?? 200);
                }
            }
        }

        return $this->withoutResourceSuffix($spec);
    }

    /**
     * Scramble drops `nullable` from a field that has nested rules
     * (`field` and `field.key`): the request schemas take it from the rules
     * of their FormRequest again.
     *
     * @param  array<string, mixed>  $schemas
     * @return array<string, mixed>
     */
    private static function withNullableObjects(array $schemas): array
    {
        foreach ($schemas as $name => $schema) {
            $class = 'App\\Http\\Requests\\Api\\V2\\'.$name;
            if (! class_exists($class) || ! isset($schema['properties']) || ! is_array($schema['properties'])) {
                continue;
            }
            foreach ((new $class)->rules() as $field => $rules) {
                $property = $schema['properties'][$field] ?? null;
                if (! is_array($property) || ! in_array('nullable', (array) $rules, true) || ($property['type'] ?? null) !== 'object') {
                    continue;
                }
                $schemas[$name]['properties'][$field]['type'] = ['object', 'null'];
            }
        }

        return $schemas;
    }

    /**
     * Schemas of the API Resources are named after the object (`User`, not
     * `UserResource`): those are the type names of the SDK.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function withoutResourceSuffix(array $spec): array
    {
        $renames = [];
        foreach (array_keys($spec['components']['schemas']) as $name) {
            if (preg_match('/^(\w+)Resource$/', $name, $m) === 1 && ! isset($spec['components']['schemas'][$m[1]])) {
                $renames[$name] = $m[1];
            }
        }
        if ($renames === []) {
            return $spec;
        }
        $schemas = [];
        foreach ($spec['components']['schemas'] as $name => $schema) {
            $schemas[$renames[$name] ?? $name] = $schema;
        }
        ksort($schemas);
        $spec['components']['schemas'] = $schemas;

        $replace = [];
        foreach ($renames as $old => $new) {
            $replace['#/components/schemas/'.$old] = '#/components/schemas/'.$new;
            $replace['`'.$old.'`'] = '`'.$new.'`';
        }
        array_walk_recursive($spec, function (mixed &$value, int|string $key) use ($replace, $renames): void {
            if (! is_string($value)) {
                return;
            }
            if ($key === 'title' && isset($renames[$value])) {
                $value = $renames[$value];
            } elseif ($key === '$ref' || $key === 'description') {
                $value = strtr($value, $replace);
            }
        });

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method, array $operation, int $success): array
    {
        $write = $method !== 'get';
        $responses = [];
        foreach ($operation['responses'] ?? [] as $status => $response) {
            if ((int) $status >= 400) {
                continue;
            }
            // Scramble also infers the dry-run answer of PlanGate; it is documented once, as DryRun.
            $schema = $response['content']['application/json']['schema'] ?? null;
            if ($schema !== null) {
                $schema = self::withoutDryRun($schema);
                if ($schema === null) {
                    continue;
                }
                $response['content']['application/json']['schema'] = self::withEmptyMeta($schema);
            }
            if (! isset($response['$ref'])) {
                $response['headers'] = self::successHeaders((string) $status) + ($response['headers'] ?? []);
            }
            $responses[$status] = $response;
        }

        // The success status of the operation: the @status tag of its controller method
        if ($success === 204) {
            $responses = array_filter($responses, fn ($status) => (int) $status >= 300, ARRAY_FILTER_USE_KEY);
            $responses['204'] = ['description' => 'No content.', 'headers' => self::successHeaders('204')];
        } elseif ($success !== 200 && isset($responses['200']) && ! isset($responses[(string) $success])) {
            $moved = $responses['200'];
            unset($responses['200']);
            $moved['headers'] = self::successHeaders((string) $success) + ($moved['headers'] ?? []);
            $responses[(string) $success] = $moved;
        }

        if ($write) {
            $operation['parameters'] = [...($operation['parameters'] ?? []), [
                'name' => 'dry_run',
                'in' => 'query',
                'description' => 'Validate, authorize and plan the change, and answer with its steps instead of running them.',
                'schema' => ['type' => 'boolean', 'default' => false],
            ]];
            $dryRun = ['$ref' => '#/components/schemas/DryRun'];
            if (isset($responses['200']['content']['application/json']['schema'])) {
                $schema = $responses['200']['content']['application/json']['schema'];
                $responses['200']['content']['application/json']['schema'] = ['oneOf' => [$schema, $dryRun]];
                $responses['200']['description'] = trim(($responses['200']['description'] ?? '').'; with `dry_run`, the planned steps.');
            } else {
                $responses['200'] = [
                    'description' => 'With `dry_run`: the planned steps; nothing ran.',
                    'headers' => self::successHeaders('200'),
                    'content' => ['application/json' => ['schema' => $dryRun]],
                ];
            }
        }

        $errors = ['401', '403'];
        if (str_contains($path, '{')) {
            $errors[] = '404';
        }
        $query = array_filter($operation['parameters'] ?? [], fn ($parameter) => ($parameter['in'] ?? '') === 'query' && ($parameter['name'] ?? '') !== 'dry_run');
        if ($write) {
            array_push($errors, '409', '422');
        } elseif ($query !== []) {
            $errors[] = '422';
        }
        array_push($errors, '429', '500');
        if ($write) {
            $errors[] = '503';
        }
        foreach ($errors as $status) {
            $responses[$status] = ['$ref' => '#/components/responses/'.self::ERROR_STATUSES[$status][0]];
        }
        uksort($responses, fn ($a, $b) => (int) $a <=> (int) $b);
        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * The success status of each operation other than 200, from the
     * `@status` tag of its controller method (Scramble does not follow
     * `setStatusCode()` and `noContent()` after an early return).
     *
     * @return array<string, int> "method /path" => status
     */
    private function declaredStatuses(): array
    {
        $statuses = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $action = $route->getAction('uses');
            if (! str_starts_with($route->uri(), 'api/v2/') || ! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            $doc = (string) (new ReflectionMethod($class, $method))->getDocComment();
            if (preg_match('/@status\s+(\d{3})/', $doc, $m) !== 1) {
                continue;
            }
            foreach ($route->methods() as $verb) {
                if ($verb !== 'HEAD') {
                    $statuses[strtolower($verb).' /'.substr($route->uri(), strlen('api/v2/'))] = (int) $m[1];
                }
            }
        }

        return $statuses;
    }

    /**
     * The schema without the alternatives Scramble infers for the dry-run
     * answer (`{"data": {"dry_run": …}}`) or for an untyped JSON response;
     * null when nothing else is left.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private static function withoutDryRun(array $schema): ?array
    {
        $isDryRun = fn (array $alternative): bool => isset($alternative['properties']['data']['properties']['dry_run'])
            || ($alternative === ['type' => 'object']);

        foreach (['anyOf', 'oneOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $left = array_values(array_filter($schema[$key], fn ($alternative) => is_array($alternative) && ! $isDryRun($alternative)));

                return match (count($left)) {
                    0 => null,
                    1 => $left[0],
                    default => [...$schema, $key => $left],
                };
            }
        }

        return $isDryRun($schema) ? null : $schema;
    }

    /**
     * `meta` of a list is an object, empty until pagination exists (spec D9).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function withEmptyMeta(array $schema): array
    {
        if (isset($schema['properties']['meta'])) {
            $schema['properties']['meta'] = ['type' => 'object', 'description' => 'Reserved for pagination; empty for now.'];
        }

        return $schema;
    }

    /** @return array<string, array{'$ref': string}> */
    private static function successHeaders(string $status): array
    {
        return ['X-Request-Id' => ['$ref' => '#/components/headers/RequestId'], 'ArcPanel-Version' => ['$ref' => '#/components/headers/Version']]
            + (in_array($status, ['201', '202'], true) ? ['Location' => ['$ref' => '#/components/headers/Location']] : []);
    }

    /** @return array<string, mixed> */
    private function schemas(): array
    {
        $step = [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string', 'description' => 'What the step does.'],
                'command' => ['type' => 'string', 'description' => 'The v-* command of the step.'],
            ],
            'required' => ['label', 'command'],
        ];

        return [
            'ErrorCode' => [
                'type' => 'string',
                'description' => 'Error names of the command exit codes (func/main.sh) and of the API layer.',
                'enum' => array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases()),
            ],
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['$ref' => '#/components/schemas/ErrorCode'],
                            'message' => ['type' => 'string'],
                            'fields' => [
                                'type' => 'object',
                                'description' => 'E_VALIDATION: the messages of each invalid field.',
                                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'commands' => [
                                'type' => 'array',
                                'description' => 'E_FORBIDDEN by a permission set: the commands the key may not run.',
                                'items' => ['type' => 'string'],
                            ],
                            'step' => ['description' => 'The step of a change that failed.', 'anyOf' => [['$ref' => '#/components/schemas/PlanStep'], ['type' => 'null']]],
                            'applied' => ['type' => 'array', 'description' => 'The steps that ran before the failed one; they stay applied.', 'items' => ['$ref' => '#/components/schemas/PlanStep']],
                        ],
                        'required' => ['code', 'message'],
                    ],
                ],
                'required' => ['error'],
            ],
            'PlanStep' => $step,
            'DryRun' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'dry_run' => ['type' => 'boolean', 'const' => true],
                            'steps' => [
                                'type' => 'array',
                                'items' => array_merge_recursive($step, [
                                    'properties' => ['args' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Arguments of the command; secrets are masked.']],
                                    'required' => ['args'],
                                ]),
                            ],
                        ],
                        'required' => ['dry_run', 'steps'],
                    ],
                ],
                'required' => ['data'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function headers(): array
    {
        return [
            'RequestId' => ['description' => 'The id of the request: the client\'s X-Request-Id, or a new one.', 'schema' => ['type' => 'string']],
            'Version' => ['description' => 'Version of the panel.', 'schema' => ['type' => 'string']],
            'RetryAfter' => ['description' => 'Seconds to wait before the next request.', 'schema' => ['type' => 'integer']],
            'Location' => ['description' => 'URL of the resource (201) or of the resource to poll (202).', 'schema' => ['type' => 'string']],
        ];
    }

    /** @return array<string, mixed> */
    private function errorResponses(): array
    {
        $responses = [];
        foreach (self::ERROR_STATUSES as $status => [$name, $description]) {
            $headers = ['X-Request-Id' => ['$ref' => '#/components/headers/RequestId'], 'ArcPanel-Version' => ['$ref' => '#/components/headers/Version']];
            if ($status === '429') {
                $headers['Retry-After'] = ['$ref' => '#/components/headers/RetryAfter'];
            }
            $responses[$name] = [
                'description' => $description,
                'headers' => $headers,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }

        return $responses;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function problems(array $spec): array
    {
        $problems = [];
        $seen = [];
        foreach ($this->operations($spec) as [$path, $method, $operation]) {
            $id = (string) ($operation['operationId'] ?? '');
            if (preg_match('/^[a-z][A-Za-z0-9]+$/', $id) !== 1) {
                $problems[] = strtoupper($method)." {$path}: operationId '{$id}' is missing or not camelCase (add @operationId to the controller method).";
            } elseif (isset($seen[$id])) {
                $problems[] = strtoupper($method)." {$path}: operationId {$id} is used by {$seen[$id]} too.";
            }
            $seen[$id] = strtoupper($method).' '.$path;
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function operations(array $spec): array
    {
        $list = [];
        foreach ($spec['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation) && in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $list[] = [$path, $method, $operation];
                }
            }
        }

        return $list;
    }
}
