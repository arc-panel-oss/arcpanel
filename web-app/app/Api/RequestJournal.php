<?php

namespace App\Api;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The request journal of REST API v2 (E43 B4,
 * docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §7):
 * two JSON lines per write of a key in `<arc.request_journal>/YYYY-MM-DD.jsonl`,
 * one before the controller runs and one when the answer is built, so that a
 * client whose answer was lost can ask what happened (`GET /requests/{id}`).
 *
 * API telemetry, not server state (D9): no `v-*` command writes it, and
 * `v-update-sys-queue daily` removes the days older than the retention.
 * Metadata only: never a request body. Both lines of a request carry the
 * same `entry`, so that requests sharing a client's id stay apart.
 */
final class RequestJournal
{
    public const RETENTION_DAYS = 7;

    /** A request without an end is `running` this long, `unknown` after. */
    public const RUNNING_MINUTES = 15;

    private const ATTRIBUTE = 'arc_journal';

    private const TIME = 'Y-m-d\TH:i:s\Z';

    /** @var array<string, string> operationId by controller action */
    private static array $operations = [];

    /** The first line, before the controller runs. */
    public function start(Request $request, KeyPermissions $key): void
    {
        $now = CarbonImmutable::now('UTC');
        $file = $this->file($now);
        $entry = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, ['entry' => $entry, 'file' => $file]);

        $this->append($file, [
            'entry' => $entry,
            'id' => ApiHeaders::requestId($request),
            'key' => $key->keyId,
            'user' => $key->user,
            'operation' => self::operation($request->route()),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'started_at' => $now->format(self::TIME),
        ]);
    }

    /** The second line, in the file of the first one, once the answer is built. */
    public function finish(Request $request, Response $response): void
    {
        $started = $request->attributes->get(self::ATTRIBUTE);
        if (! is_array($started)) {
            return;
        }
        $request->attributes->remove(self::ATTRIBUTE);
        $status = $response->getStatusCode();

        $this->append($started['file'], [
            'entry' => $started['entry'],
            'id' => ApiHeaders::requestId($request),
            'status' => $status,
            'error_code' => $status >= 400 ? self::errorCode($response) : null,
            'task_id' => $status === 202 ? self::taskId($response) : null,
            'location' => in_array($status, [201, 202], true) ? $response->headers->get('Location') : null,
            'finished_at' => CarbonImmutable::now('UTC')->format(self::TIME),
        ]);
    }

    /**
     * The latest request with $id that started within the retention and that
     * $user made (every user's for null), and the number of such requests.
     *
     * @return array{request: array<string, mixed>, duplicates: int}|null
     */
    public function find(string $id, ?string $user): ?array
    {
        $now = CarbonImmutable::now('UTC');
        $oldest = $now->subDays(self::RETENTION_DAYS);
        $needle = '"id":'.json_encode($id, JSON_UNESCAPED_SLASHES);
        $starts = [];
        $ends = [];
        for ($day = self::RETENTION_DAYS; $day >= 0; $day--) {
            $handle = @fopen($this->file($now->subDays($day)), 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                if (! str_contains($line, $needle)) {
                    continue;
                }
                $row = json_decode($line, true);
                if (! is_array($row) || ($row['id'] ?? null) !== $id || ! is_string($row['entry'] ?? null)) {
                    continue;
                }
                if (! isset($row['started_at'])) {
                    $ends[$row['entry']] = $row;

                    continue;
                }
                $started = self::time($row['started_at']);
                if ($started !== null && $started->greaterThanOrEqualTo($oldest) && ($user === null || ($row['user'] ?? null) === $user)) {
                    $starts[] = $row + ['started' => $started];
                }
            }
            fclose($handle);
        }
        if ($starts === []) {
            return null;
        }

        $start = $starts[array_key_last($starts)];
        $end = $ends[$start['entry']] ?? null;
        $state = match (true) {
            $end !== null => 'finished',
            $start['started']->greaterThan($now->subMinutes(self::RUNNING_MINUTES)) => 'running',
            default => 'unknown',
        };

        return [
            'request' => [
                'id' => $id,
                'operation' => (string) ($start['operation'] ?? ''),
                'method' => (string) ($start['method'] ?? ''),
                'path' => (string) ($start['path'] ?? ''),
                'state' => $state,
                'status' => isset($end['status']) ? (int) $end['status'] : null,
                'error_code' => $end['error_code'] ?? null,
                'task_id' => $end['task_id'] ?? null,
                'location' => $end['location'] ?? null,
                'started_at' => (string) $start['started_at'],
                'finished_at' => $end['finished_at'] ?? null,
            ],
            'duplicates' => count($starts),
        ];
    }

    private function file(CarbonImmutable $day): string
    {
        return rtrim((string) config('arc.request_journal'), '/').'/'.$day->format('Y-m-d').'.jsonl';
    }

    /**
     * One line, appended under a lock; readable by the panel's web user only.
     * A journal that cannot be written never fails the request.
     *
     * @param  array<string, mixed>  $row
     */
    private function append(string $file, array $row): void
    {
        $umask = umask(0077);
        try {
            $dir = dirname($file);
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException('cannot create '.$dir);
            }
            if (file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('cannot write '.$file);
            }
        } catch (Throwable $e) {
            Log::warning('The API request journal could not be written.', ['error' => $e->getMessage()]);
        } finally {
            umask($umask);
        }
    }

    /** The operationId of the route's controller method, or its route name. */
    private static function operation(?Route $route): string
    {
        if ($route === null) {
            return '';
        }
        $action = $route->getActionName();
        if (! array_key_exists($action, self::$operations)) {
            $operation = (string) $route->getName();
            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action, 2);
                try {
                    $doc = (string) (new ReflectionMethod($class, $method))->getDocComment();
                    if (preg_match('/@operationId\s+(\w+)/', $doc, $m) === 1) {
                        $operation = $m[1];
                    }
                } catch (Throwable) {
                    // the route name stays
                }
            }
            self::$operations[$action] = $operation;
        }

        return self::$operations[$action];
    }

    private static function errorCode(Response $response): ?string
    {
        $content = $response->getContent();
        $data = is_string($content) ? json_decode($content, true) : null;
        $code = is_array($data) ? ($data['error']['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    /** The task of `Link: </api/v2/tasks/{id}>; rel="monitor"` (E34 batch 3 D37). */
    private static function taskId(Response $response): ?string
    {
        return preg_match('#</api/v2/tasks/([^>]+)>;\s*rel="monitor"#', (string) $response->headers->get('Link', ''), $m) === 1 ? $m[1] : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $time = CarbonImmutable::createFromFormat(self::TIME, $value, 'UTC');

        return $time instanceof CarbonImmutable ? $time : null;
    }
}
