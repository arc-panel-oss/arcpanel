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
    /** 202 answers that queue a task with `Link: …; rel="monitor"` only (no `Location`). */
    private const TASK_MONITOR_202 = [
        'installNodeVersion',
        'installNodePackage',
        'updateNodePackages',
        'checkNodePackages',
        'installNodeApp',
        'buildNodeApp',
        'startDockerAppTask',
    ];

    /** List operations whose `meta` is not paginated `ListMeta` (E34 batch 3 §5, batch 4 §4.2). */
    private const LIST_WITHOUT_PAGINATION = ['listSystemTasks', 'listNodeApps', 'listDockerApps', 'listDockerCatalog', 'listPorts'];

    /** Operations that answer the ports of one account with `PortListMeta` (E34 batch 4 §4.5). */
    private const PORT_LISTS = ['listPorts', 'givePorts', 'releasePort', 'setPortPublicOk', 'reclaimPorts', 'allocatePorts', 'registerPort'];

    private const ERROR_STATUSES = [
        '401' => ['Unauthenticated', 'The access key is missing, unknown or wrong (`E_UNAUTHENTICATED`).'],
        '403' => ['Forbidden', 'The API is off for this key (`E_API_DISABLED`), the role or a permission set refuses the call (`E_FORBIDDEN`), or the owner is suspended (`E_SUSPENDED`).'],
        '404' => ['NotFound', 'No such object in the scope of the key (`E_NOTEXIST`).'],
        '410' => ['Gone', 'The task result is no longer kept (`E_GONE`).'],
        '409' => ['Conflict', 'The object exists or is in use (`E_EXISTS`, `E_INUSE`).'],
        '413' => ['TooLarge', 'The file or the content is larger than the limit of the operation (`E_LIMIT`).'],
        '422' => ['ValidationFailed', 'The request is invalid (`E_VALIDATION` with `fields`, or a command refused an argument), or a package limit is reached (`E_LIMIT`).'],
        '429' => ['TooManyRequests', 'Too many requests (`E_RATE_LIMIT`); `Retry-After` names the seconds to wait.'],
        '500' => ['ServerError', 'A command failed on the server.'],
        '503' => ['Unavailable', 'The service is disabled or the server is too busy (`E_DISABLED`, `E_LA`).'],
        '507' => ['InsufficientStorage', 'The panel has too little space to stage the data now (`E_DISK`).'],
    ];

    /** File operations that answer 413 or 507 (E34 batch 5 D55). */
    private const FILE_ERRORS = ['readFile' => ['413'], 'saveFile' => ['413', '507']];

    private const DESCRIPTION = <<<'MD'
        Resource API of an ArcPanel server, served by the panel at `/api/v2/` (port 8083 by default). Every call acts as the owner of the access key, with the role and the reach that user has in the panel: objects outside its reach answer `404`, refused actions `403`.

        **Authentication.** `Authorization: Bearer ACCESS_KEY_ID:SECRET_ACCESS_KEY`, an access key of the panel (Account → Access keys). The API must be on (`API_SYSTEM` 1 for the administrator's keys, 2 for every key) and accept the client's address (`API_ALLOWED_IP`). A key with permission sets may only run their commands. `v2-read` allows every read endpoint and `v2-write` every endpoint; each area also has its own set (`v2-accounts`, `v2-web`, and the sets of later areas). A change that touches another area needs that area's set too. API v1 refuses every set marked for v2. `GET /me` describes the key.

        **Pagination.** Every list accepts `limit` (1–1000) and `offset` (0 or more). `meta.total` is the number of items. Without `limit` the list is complete, as before pagination existed.

        **Dry run.** Every write accepts `?dry_run=1`: the request is validated, authorized and checked against the permission sets, and the answer lists the commands it would run, without running any.

        **Errors.** Every error answers `{"error": {"code": "E_…", "message": "…"}}`; `code` is one of `ErrorCode`. Validation errors add `fields`, refused permission sets add `commands`, a duplicate the server can point at adds `existing` (the path of that object, or a queued backup or restore task), and a failed step of a change adds `step` and the steps that ran before it (`applied`), which stay applied. File operations add the names that exist in the destination (`conflicts`) and, when they stop part-way, the path that failed (`path`) and the paths already finished (`done`).

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

        $spec = $this->document($this->normalizePaths(app(Generator::class)->generate(Scramble::getGeneratorConfig('default'))->spec()));
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

        if (isset($spec['components']['schemas']['DockerApp'])) {
            $spec['components']['schemas']['DockerApp'] = self::dockerAppSchema();
        }
        unset($spec['components']['schemas']['DockerAppResource']);
        // Scramble types the fields of the port resources as strings; the schemas are written out (E34 batch 4 §4.5).
        foreach (self::portComponentSchemas() as $name => $schema) {
            $spec['components']['schemas'][$name] = $schema;
        }
        ksort($spec['components']['schemas']);

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method, array $operation, int $success): array
    {
        $operationId = (string) ($operation['operationId'] ?? '');
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
                $response['content']['application/json']['schema'] = self::applyListMeta($schema, $operationId);
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

        if (str_starts_with($operationId, 'list') && ! in_array($operationId, self::LIST_WITHOUT_PAGINATION, true)) {
            $operation['parameters'] = [...($operation['parameters'] ?? []), ...self::pageParameters()];
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
        if (($operation['operationId'] ?? '') === 'getTask') {
            $errors[] = '410';
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
        array_push($errors, ...(self::FILE_ERRORS[$operationId] ?? []));
        foreach ($errors as $status) {
            $responses[$status] = ['$ref' => '#/components/responses/'.self::ERROR_STATUSES[$status][0]];
        }
        if (($operation['operationId'] ?? '') === 'downloadBackup') {
            $responses['200'] = [
                'description' => 'The archive when it is on this server: an empty body with `X-Accel-Redirect` so nginx serves the bytes (D35).',
                'headers' => self::successHeaders('200') + [
                    'Content-Type' => ['description' => 'Usually `application/octet-stream` or `application/x-gzip`.', 'schema' => ['type' => 'string']],
                    'Content-Disposition' => ['description' => '`attachment; filename="<backup>"`', 'schema' => ['type' => 'string']],
                    'X-Accel-Redirect' => ['description' => 'Internal nginx path to the archive file (D35).', 'schema' => ['type' => 'string']],
                ],
                'content' => [
                    'application/octet-stream' => [
                        'schema' => ['type' => 'string', 'format' => 'binary'],
                    ],
                ],
            ];
        }
        if (($operation['operationId'] ?? '') === 'dumpDatabase') {
            $responses['200'] = [
                'description' => 'The gzip dump: an empty body with `X-Accel-Redirect` so nginx serves the bytes (D35).',
                'headers' => self::successHeaders('200') + [
                    'Content-Type' => ['description' => '`application/gzip`.', 'schema' => ['type' => 'string']],
                    'Content-Disposition' => ['description' => '`attachment; filename="<dump>.sql.gz"`', 'schema' => ['type' => 'string']],
                    'X-Accel-Redirect' => ['description' => 'Internal nginx path under `/backup/` (D35).', 'schema' => ['type' => 'string']],
                ],
                'content' => [
                    'application/gzip' => [
                        'schema' => ['type' => 'string', 'format' => 'binary'],
                    ],
                ],
            ];
        }
        if (in_array($operationId, self::TASK_MONITOR_202, true) && isset($responses['202']['headers'])) {
            unset($responses['202']['headers']['Location']);
            $responses['202']['headers']['Link'] = ['$ref' => '#/components/headers/Link'];
        }
        self::tailorNodeAppOpenApi($operationId, $operation, $responses);
        self::tailorDockerAppOpenApi($operationId, $operation, $responses);
        self::tailorPortOpenApi($operationId, $operation, $responses);
        self::tailorFileOpenApi($operationId, $operation, $responses);
        uksort($responses, fn ($a, $b) => (int) $a <=> (int) $b);
        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * Scramble may prefix paths with `/v2/` while `servers.url` already ends in `/api/v2`.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function normalizePaths(array $spec): array
    {
        $paths = [];
        foreach ($spec['paths'] ?? [] as $path => $operations) {
            $paths[self::documentPath($path)] = $operations;
        }
        ksort($paths);
        $spec['paths'] = $paths;

        return $spec;
    }

    private static function documentPath(string $path): string
    {
        if ($path === '/v2/me') {
            return '/me';
        }
        if (str_starts_with($path, '/v2/')) {
            return substr($path, 3);
        }

        return $path;
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
     * `meta` of a list is `ListMeta`: `total`, and `limit` with `offset` when the client pages (D20).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function applyListMeta(array $schema, string $operationId): array
    {
        if (in_array($operationId, self::PORT_LISTS, true)) {
            return [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PortEntry']],
                    'meta' => ['$ref' => '#/components/schemas/PortListMeta'],
                ],
                'required' => ['data', 'meta'],
            ];
        }
        if (! isset($schema['properties']['meta'])) {
            return $schema;
        }
        if ($operationId === 'listNodeApps') {
            $schema['properties']['meta'] = ['$ref' => '#/components/schemas/NodeAppListMeta'];

            return $schema;
        }
        if ($operationId === 'listDockerApps') {
            $schema['properties']['meta'] = ['$ref' => '#/components/schemas/DockerAppListMeta'];

            return $schema;
        }
        if ($operationId === 'getNodeAppLogs') {
            $schema['properties']['meta'] = ['$ref' => '#/components/schemas/NodeAppLogMeta'];

            return $schema;
        }
        if ($operationId === 'getDockerAppLogs') {
            $schema['properties']['meta'] = ['$ref' => '#/components/schemas/DockerAppLogMeta'];

            return $schema;
        }
        if ($operationId === 'searchFiles') {
            $schema['properties']['meta'] = ['$ref' => '#/components/schemas/FileSearchMeta'];

            return $schema;
        }

        return self::withListMeta($schema);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function withListMeta(array $schema): array
    {
        if (isset($schema['properties']['meta'])) {
            $schema['properties']['meta'] = ['$ref' => '#/components/schemas/ListMeta'];
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $responses
     */
    private static function tailorDockerAppOpenApi(string $operationId, array &$operation, array &$responses): void
    {
        if ($operationId === 'createDockerApp' && isset($responses['202']['headers'])) {
            $responses['202']['headers']['Link'] = ['$ref' => '#/components/headers/Link'];
        }
        if ($operationId === 'getDockerAppLogs') {
            $operation['parameters'] = [...($operation['parameters'] ?? []), ...self::dockerAppLogParameters()];
            if (isset($responses['200']['content']['application/json']['schema'])) {
                $responses['200']['description'] = 'Log lines and a cursor for the next snapshot (D43).';
            }
        }
        if ($operationId === 'getDockerAppEnv' && isset($responses['200']['content']['application/json']['schema'])) {
            $responses['200']['content']['application/json']['schema'] = self::dockerAppEnvBodySchema();
            $responses['200']['description'] = 'Environment variables with secrets masked (D42).';
        }
        if ($operationId === 'setDockerAppEnv' && isset($responses['200'])) {
            $env = self::dockerAppEnvBodySchema();
            $dryRun = ['$ref' => '#/components/schemas/DryRun'];
            $responses['200']['content']['application/json']['schema'] = ['oneOf' => [$env, $dryRun]];
            $responses['200']['description'] = 'The saved environment (D42); with `dry_run`, the planned steps.';
        }
    }

    /** @return array<string, mixed> */
    private static function dockerAppSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'One Docker app. Detail fields appear on `getDockerApp` only.',
            'properties' => [
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'app_name' => ['type' => 'string'],
                'version' => ['type' => 'string'],
                'latest_version' => ['type' => 'string'],
                'update_available' => ['type' => 'boolean'],
                'domain' => ['type' => ['string', 'null']],
                'url' => ['type' => ['string', 'null']],
                'ssl' => ['type' => 'boolean'],
                'web_port' => ['type' => ['string', 'null']],
                'extra_ports' => ['type' => ['string', 'null']],
                'state' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'reason' => ['type' => ['string', 'null']],
                'suspended' => ['type' => 'boolean'],
                'busy' => ['type' => 'boolean'],
                'previous_version' => ['type' => ['string', 'null']],
                'cpus' => ['type' => 'string'],
                'memory_mb' => ['type' => 'integer'],
                'cpu' => ['type' => 'number'],
                'memory_used_mb' => ['type' => 'integer'],
                'containers' => ['type' => 'integer'],
                'running_containers' => ['type' => 'integer'],
                'created_at' => ['type' => ['string', 'null']],
                'services' => [
                    'type' => 'array',
                    'description' => 'Compose services; present on detail only.',
                    'items' => ['$ref' => '#/components/schemas/DockerAppServiceEntry'],
                ],
                'ports' => [
                    'type' => 'array',
                    'description' => 'Published ports; present on detail only.',
                    'items' => ['$ref' => '#/components/schemas/DockerAppPortEntry'],
                ],
                'post_install' => [
                    'type' => ['string', 'null'],
                    'description' => 'Post-install note; present on detail only.',
                ],
                'rollback' => [
                    'type' => 'boolean',
                    'description' => 'Whether a rollback is available; present on detail only.',
                ],
                'last_dump' => [
                    'type' => ['string', 'null'],
                    'description' => 'Last database dump path; present on detail only.',
                ],
            ],
            'required' => [
                'name', 'slug', 'app_name', 'version', 'latest_version', 'update_available', 'domain', 'url', 'ssl',
                'web_port', 'extra_ports', 'state', 'status', 'reason', 'suspended', 'busy', 'previous_version', 'cpus',
                'memory_mb', 'cpu', 'memory_used_mb', 'containers', 'running_containers', 'created_at',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function dockerAppComponentSchemas(): array
    {
        return [
            'DockerAppPortEntry' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'port' => ['type' => 'string'],
                    'container' => ['type' => 'string'],
                    'public' => ['type' => 'boolean'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['name', 'port', 'container', 'public', 'description'],
            ],
            'DockerAppServiceEntry' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'image' => ['type' => 'string'],
                    'state' => ['type' => 'string'],
                    'health' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                ],
                'required' => ['service', 'image', 'state', 'health', 'status'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function dockerAppEnvBodySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/DockerAppEnvEntry'],
                ],
            ],
            'required' => ['data'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function dockerAppLogParameters(): array
    {
        return [
            [
                'name' => 'lines',
                'in' => 'query',
                'description' => 'Number of lines to return (1–1000). Defaults to 200.',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
            ],
            [
                'name' => 'after',
                'in' => 'query',
                'description' => 'Opaque cursor from `meta.next` of a previous snapshot; returns lines that appeared since.',
                'schema' => ['type' => 'string', 'maxLength' => 128],
            ],
            [
                'name' => 'service',
                'in' => 'query',
                'description' => 'Compose service to read (`all` for every service).',
                'schema' => ['type' => 'string', 'default' => 'all'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $responses
     */
    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $responses
     */
    private static function tailorPortOpenApi(string $operationId, array &$operation, array &$responses): void
    {
        $dryRun = ['$ref' => '#/components/schemas/DryRun'];
        if (in_array($operationId, ['releasePort', 'setPortPublic'], true)) {
            $operation['parameters'] = [...($operation['parameters'] ?? []), [
                'name' => 'proto',
                'in' => 'query',
                'description' => 'Act only when the port is used with this protocol (404 otherwise).',
                'schema' => ['type' => 'string', 'enum' => ['tcp', 'udp']],
            ]];
        }
        foreach ($operation['parameters'] ?? [] as $i => $parameter) {
            if (($parameter['name'] ?? '') === 'range' && ($parameter['in'] ?? '') === 'path') {
                $operation['parameters'][$i]['description'] = 'A port (`30005`) or a range (`30010-30019`).';
                $operation['parameters'][$i]['schema'] = ['type' => 'string', 'pattern' => '^\d{1,5}(-\d{1,5})?$'];
            }
        }
        if (in_array($operationId, self::PORT_LISTS, true) && isset($responses['200'])) {
            $write = isset($responses['200']['content']['application/json']['schema']['oneOf']);
            $responses['200']['description'] = 'The ports of the account with the totals of its pool'.($write ? '; with `dry_run`, the planned steps.' : '.');
        }
        if ($operationId === 'requestPorts') {
            $responses['200']['description'] = 'The owner that was notified and the count asked for; with `dry_run`, the planned steps.';
            $responses['200']['content']['application/json']['schema'] = ['oneOf' => [[
                'type' => 'object',
                'properties' => ['data' => [
                    'type' => 'object',
                    'properties' => [
                        'owner' => ['type' => 'string', 'description' => 'The reseller or administrator that received the notification.'],
                        'count' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['owner', 'count'],
                ]],
                'required' => ['data'],
            ], $dryRun]];
        }
        if ($operationId === 'repairPorts') {
            $responses['200']['description'] = 'The findings left after the repair; with `dry_run`, the planned steps.';
            $responses['200']['content']['application/json']['schema'] = ['oneOf' => [[
                'type' => 'object',
                'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PortFinding']]],
                'required' => ['data'],
            ], $dryRun]];
        }
    }

    /**
     * The file endpoints (E34 batch 5 §4): the boolean `hidden` of
     * listFiles, and the descriptions of the answers Scramble leaves empty.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $responses
     */
    private static function tailorFileOpenApi(string $operationId, array &$operation, array &$responses): void
    {
        if ($operationId === 'listFiles') {
            foreach ($operation['parameters'] ?? [] as $i => $parameter) {
                if (($parameter['name'] ?? '') === 'hidden' && ($parameter['in'] ?? '') === 'query') {
                    $operation['parameters'][$i]['schema'] = ['type' => 'boolean', 'default' => true];
                }
            }
        }
        if (in_array($operationId, ['copyFiles', 'moveFiles'], true) && isset($responses['200'])) {
            $responses['200']['description'] = 'The folder and the names created in it; with `dry_run`, the planned steps.';
        }
        if ($operationId === 'getFolderSize' && isset($responses['200'])) {
            $responses['200']['description'] = 'The bytes of the folder and everything in it.';
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function portComponentSchemas(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $range = [
            'type' => 'object',
            'properties' => ['start' => ['type' => 'integer'], 'end' => ['type' => 'integer']],
            'required' => ['start', 'end'],
        ];

        return [
            'PortEntry' => [
                'type' => 'object',
                'title' => 'PortEntry',
                'description' => 'One entry of the port registry: a single port or a free range of a user.',
                'properties' => [
                    'port' => ['type' => 'string', 'description' => '`30001`, or `30010-30019` for a range.'],
                    'start' => ['type' => 'integer'],
                    'end' => ['type' => 'integer'],
                    'size' => ['type' => 'integer', 'minimum' => 1],
                    'proto' => ['type' => 'string', 'enum' => ['tcp', 'udp']],
                    'owner' => ['type' => 'string', 'description' => 'The account that holds the port.'],
                    'app' => ['type' => ['string', 'null'], 'description' => '`manual`, `node:<domain>` or `docker:<app>`; null for a free port or range.'],
                    'assigned' => ['type' => 'boolean'],
                    'public' => ['type' => 'boolean', 'description' => 'Open to the internet in the registry (closed while the owner is suspended).'],
                    'public_ok' => ['type' => 'boolean', 'description' => 'Whether the port may be opened to the internet.'],
                    'rule' => ['type' => ['string', 'null'], 'description' => 'Id of the port\'s firewall rule, null without one.'],
                    'comment' => $nullableString,
                    'suspended' => ['type' => 'boolean'],
                    'listen' => ['type' => 'string', 'enum' => ['no', 'local', 'any'], 'description' => 'Live state: nothing listens, loopback only, or all addresses.'],
                    'created_at' => ['type' => ['string', 'null']],
                ],
                'required' => ['port', 'start', 'end', 'size', 'proto', 'owner', 'app', 'assigned', 'public', 'public_ok', 'rule', 'comment', 'suspended', 'listen', 'created_at'],
            ],
            'PortFinding' => [
                'type' => 'object',
                'title' => 'PortFinding',
                'description' => 'A reconciliation finding of the port registry.',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['orphan-socket', 'foreign-socket', 'exposed-private', 'missing-rule', 'stale-rule', 'unmanaged-rule', 'orphan-file', 'over-limit', 'orphan-web']],
                    'title' => ['type' => 'string'],
                    'user' => $nullableString,
                    'port' => $nullableString,
                    'proto' => $nullableString,
                    'rule' => $nullableString,
                    'detail' => ['type' => 'string'],
                ],
                'required' => ['type', 'title', 'user', 'port', 'proto', 'rule', 'detail'],
            ],
            'PortRegistry' => [
                'type' => 'object',
                'title' => 'PortRegistry',
                'description' => 'The port registry of the server (Admin → Server → Ports).',
                'properties' => [
                    'range' => $range,
                    'firewall' => ['type' => ['string', 'null'], 'description' => 'FIREWALL_SYSTEM; null when the firewall is off and every listening port is reachable.'],
                    'users' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'user' => ['type' => 'string'],
                            'owner' => $nullableString,
                            'role' => ['type' => 'string'],
                            'suspended' => ['type' => 'boolean'],
                            'limit' => ['type' => 'string', 'description' => 'PORTS of the package (`unlimited` or a number).'],
                            'allocated' => ['type' => 'integer'],
                            'assigned' => ['type' => 'integer'],
                            'public' => ['type' => 'integer'],
                            'free' => ['type' => 'integer'],
                            'tree_total' => ['type' => ['integer', 'null']],
                            'tree_used' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['user', 'owner', 'role', 'suspended', 'limit', 'allocated', 'assigned', 'public', 'free', 'tree_total', 'tree_used'],
                    ]],
                    'entries' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PortEntry']],
                    'reserved' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'port' => ['type' => 'string'],
                            'start' => ['type' => 'integer'],
                            'end' => ['type' => 'integer'],
                            'kind' => ['type' => 'string', 'enum' => ['system', 'admin'], 'description' => '`system`: a service of the panel (kept); `admin`: reserved by an administrator.'],
                            'comment' => $nullableString,
                        ],
                        'required' => ['port', 'start', 'end', 'kind', 'comment'],
                    ]],
                    'sockets' => ['type' => 'array', 'description' => 'Sockets that listen in the server range.', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'proto' => ['type' => 'string', 'enum' => ['tcp', 'udp']],
                            'port' => ['type' => 'integer'],
                            'scope' => ['type' => 'string', 'enum' => ['local', 'any']],
                            'uid' => ['type' => ['integer', 'null']],
                            'account' => $nullableString,
                        ],
                        'required' => ['proto', 'port', 'scope', 'uid', 'account'],
                    ]],
                    'findings' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PortFinding']],
                ],
                'required' => ['range', 'firewall', 'users', 'entries', 'reserved', 'sockets', 'findings'],
            ],
        ];
    }

    private static function tailorNodeAppOpenApi(string $operationId, array &$operation, array &$responses): void
    {
        if ($operationId === 'getNodeAppLogs') {
            $operation['parameters'] = [...($operation['parameters'] ?? []), ...self::nodeAppLogParameters()];
            if (isset($responses['200']['content']['application/json']['schema'])) {
                $responses['200']['description'] = 'Log lines and a cursor for the next snapshot (D43).';
            }
        }
        if ($operationId === 'getNodeAppEnv' && isset($responses['200']['content']['application/json']['schema'])) {
            $responses['200']['content']['application/json']['schema'] = self::nodeAppEnvBodySchema();
            $responses['200']['description'] = 'Environment names and set flags (D41); values only with `reveal=1`.';
        }
        if ($operationId === 'setNodeAppEnv' && isset($responses['200'])) {
            $env = self::nodeAppEnvBodySchema();
            $dryRun = ['$ref' => '#/components/schemas/DryRun'];
            $responses['200']['content']['application/json']['schema'] = ['oneOf' => [$env, $dryRun]];
            $responses['200']['description'] = 'The saved environment (names and set flags; values only when the key may reveal them); with `dry_run`, the planned steps.';
        }
    }

    /** @return array<string, mixed> */
    private static function nodeAppEnvBodySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/NodeAppEnvEntry'],
                ],
            ],
            'required' => ['data'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function nodeAppLogParameters(): array
    {
        return [
            [
                'name' => 'lines',
                'in' => 'query',
                'description' => 'Number of lines to return (1–1000). Defaults to 200.',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
            ],
            [
                'name' => 'after',
                'in' => 'query',
                'description' => 'Opaque cursor from `meta.next` of a previous snapshot; returns lines that appeared since.',
                'schema' => ['type' => 'string', 'maxLength' => 64],
            ],
            [
                'name' => 'stream',
                'in' => 'query',
                'description' => 'Which log file to read.',
                'schema' => ['type' => 'string', 'enum' => ['out', 'err', 'install', 'build'], 'default' => 'out'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pageParameters(): array
    {
        return [
            [
                'name' => 'limit',
                'in' => 'query',
                'description' => 'Page size, from 1 to 1000. Without it the list is complete.',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
            ],
            [
                'name' => 'offset',
                'in' => 'query',
                'description' => 'Items to skip. Used when `limit` is set; 0 when omitted.',
                'schema' => ['type' => 'integer', 'minimum' => 0],
            ],
        ];
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
                            'existing' => [
                                'description' => 'E_EXISTS when the server can point at the duplicate: its path under /api/v2, or a queued backup or restore task (D21).',
                                'oneOf' => [
                                    ['type' => 'string'],
                                    ['$ref' => '#/components/schemas/Task'],
                                ],
                            ],
                            'step' => ['description' => 'The step of a change that failed.', 'anyOf' => [['$ref' => '#/components/schemas/PlanStep'], ['type' => 'null']]],
                            'applied' => ['type' => 'array', 'description' => 'The steps that ran before the failed one; they stay applied.', 'items' => ['$ref' => '#/components/schemas/PlanStep']],
                            'conflicts' => [
                                'type' => 'array',
                                'description' => 'E_EXISTS of a file operation: the names that exist in the destination.',
                                'items' => ['type' => 'string'],
                            ],
                            'path' => [
                                'type' => ['string', 'null'],
                                'description' => 'A failed file operation: the path (relative to the home directory) whose step failed.',
                            ],
                            'done' => [
                                'type' => 'array',
                                'description' => 'A failed file operation over several paths: the paths finished before the failure; they stay changed.',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['code', 'message'],
                    ],
                ],
                'required' => ['error'],
            ],
            'ListMeta' => [
                'type' => 'object',
                'description' => 'Without `limit` only `total` is present and `data` is the full list. With `limit`, `offset` is present too (0 when the client omits it) and `data` is that page.',
                'properties' => [
                    'total' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Items before limit and offset.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                    'timezone' => ['type' => 'string', 'description' => 'The server time zone. Present on listCronJobs.'],
                ],
                'required' => ['total'],
            ],
            'NodeAppListMeta' => [
                'type' => 'object',
                'description' => 'The user\'s pm2 daemon and NODE_APPS limits; the app list is not paginated (E34 batch 4 §4.2).',
                'properties' => [
                    'daemon' => ['type' => 'boolean', 'description' => 'Whether the user\'s pm2 daemon is running.'],
                    'totals' => [
                        'type' => 'object',
                        'properties' => [
                            'online' => ['type' => 'integer', 'minimum' => 0],
                            'stopped' => ['type' => 'integer', 'minimum' => 0],
                            'errored' => ['type' => 'integer', 'minimum' => 0],
                            'suspended' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['online', 'stopped', 'errored', 'suspended'],
                    ],
                    'limit' => ['type' => 'string', 'description' => 'Maximum apps from NODE_APPS (`unlimited` or a number).'],
                    'room' => ['type' => 'boolean', 'description' => 'Whether another app fits under the limit.'],
                ],
                'required' => ['daemon', 'totals', 'limit', 'room'],
            ],
            'PortListMeta' => [
                'type' => 'object',
                'description' => 'The totals of a user\'s port pool and the server range; the port list is not paginated (E34 batch 4 §4.5).',
                'properties' => [
                    'user' => ['type' => 'string'],
                    'owner' => ['type' => ['string', 'null'], 'description' => 'The reseller or administrator whose pool gives and takes back ports; null for administrators.'],
                    'limit' => ['type' => 'string', 'description' => 'PORTS of the package (`unlimited` or a number).'],
                    'allocated' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Ports in the pool.'],
                    'assigned' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Ports reserved or held by an app.'],
                    'public' => ['type' => 'integer', 'minimum' => 0],
                    'free' => ['type' => 'integer', 'minimum' => 0],
                    'tree_total' => ['type' => ['integer', 'null'], 'description' => 'A reseller: ports of the reseller and its customers.'],
                    'tree_used' => ['type' => ['integer', 'null'], 'description' => 'A reseller: ports given to its customers.'],
                    'firewall' => ['type' => 'boolean', 'description' => 'Whether the firewall is on; without it every listening port is reachable.'],
                    'range' => [
                        'type' => 'object',
                        'properties' => ['start' => ['type' => 'integer'], 'end' => ['type' => 'integer']],
                        'required' => ['start', 'end'],
                    ],
                ],
                'required' => ['user', 'owner', 'limit', 'allocated', 'assigned', 'public', 'free', 'tree_total', 'tree_used', 'firewall', 'range'],
            ],
            'NodeAppLogMeta' => [
                'type' => 'object',
                'description' => 'Cursor metadata for log snapshots (D43).',
                'properties' => [
                    'stream' => ['type' => 'string', 'enum' => ['out', 'err', 'install', 'build']],
                    'next' => [
                        'description' => 'Cursor for the next snapshot; pass as `after`. Null when there is nothing newer.',
                        'type' => ['string', 'null'],
                    ],
                ],
                'required' => ['stream', 'next'],
            ],
            'NodeAppEnvEntry' => [
                'type' => 'object',
                'description' => 'One environment variable (D41).',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'set' => ['type' => 'boolean', 'description' => 'Whether a non-empty value is stored.'],
                    'value' => [
                        'type' => 'string',
                        'description' => 'Present only when `reveal=1` and the key holds `v2-apps` or `v2-write`.',
                    ],
                ],
                'required' => ['name', 'set'],
            ],
            ...self::dockerAppComponentSchemas(),
            'DockerAppListMeta' => [
                'type' => 'object',
                'description' => 'Docker engine state and DOCKER_APPS limits; the app list is not paginated (E34 batch 4 §4.3).',
                'properties' => [
                    'engine' => ['type' => 'boolean', 'description' => 'Whether the Docker engine is installed on the server.'],
                    'totals' => [
                        'type' => 'object',
                        'properties' => [
                            'running' => ['type' => 'integer', 'minimum' => 0],
                            'stopped' => ['type' => 'integer', 'minimum' => 0],
                            'failed' => ['type' => 'integer', 'minimum' => 0],
                            'busy' => ['type' => 'integer', 'minimum' => 0],
                            'suspended' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['running', 'stopped', 'failed', 'busy', 'suspended'],
                    ],
                    'limit' => ['type' => 'string', 'description' => 'Maximum apps from DOCKER_APPS (`unlimited` or a number).'],
                    'room' => ['type' => 'boolean', 'description' => 'Whether another app fits under the limit.'],
                ],
                'required' => ['engine', 'totals', 'limit', 'room'],
            ],
            'FileSearchMeta' => [
                'type' => 'object',
                'description' => 'A name search answers at most 500 entries; the list is not paginated (E34 batch 5 §4.1).',
                'properties' => [
                    'truncated' => ['type' => 'boolean', 'description' => 'Whether more than 500 entries matched.'],
                ],
                'required' => ['truncated'],
            ],
            'DockerAppLogMeta' => [
                'type' => 'object',
                'description' => 'Cursor metadata for Docker log snapshots (D43).',
                'properties' => [
                    'next' => [
                        'description' => 'Cursor for the next snapshot; pass as `after`. Null when there is nothing newer.',
                        'type' => ['string', 'null'],
                    ],
                ],
                'required' => ['next'],
            ],
            'DockerAppEnvEntry' => [
                'type' => 'object',
                'description' => 'One Docker app environment variable (D42).',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'secret' => ['type' => 'boolean'],
                    'immutable' => ['type' => 'boolean'],
                    'required' => ['type' => 'boolean'],
                    'pattern' => ['type' => 'string'],
                    'set' => ['type' => 'boolean'],
                    'value' => [
                        'type' => 'string',
                        'description' => 'Omitted for secrets and generated defaults.',
                    ],
                ],
                'required' => ['name', 'label', 'type', 'secret', 'immutable', 'required', 'set'],
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
            'Link' => ['description' => 'Task monitor URL (`rel="monitor"`) for `GET /tasks/{id}`.', 'schema' => ['type' => 'string']],
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
