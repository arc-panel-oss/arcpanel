<?php

namespace App\Audit;

use App\Api\ApiHeaders;
use App\Api\KeyScopedArcCommand;
use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Auth\ClientIp;
use App\Auth\Principal;
use App\Auth\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Builds the audit events of a request (E23 spec §3, §5) and writes them
 * once the answer is sent (RecordAuditEvent::terminate). A request records
 * its explicit events (§5.2) when a controller added any, else one event
 * for a state-changing request or a sensitive read (D4). Dry runs of REST
 * API v2 change nothing and are not recorded.
 *
 * A write that fails never changes the answer (D11): the error goes to the
 * `audit` log channel and to one notification of the administrator a day.
 */
final class AuditRecorder
{
    private const EVENTS = 'audit.events';

    private const NAME = 'audit.object_name';

    private const DONE = 'audit.done';

    /** Input fields that are never fields of an event. */
    private const SKIPPED_FIELDS = ['_token', '_method', 'dry_run'];

    public function __construct(private readonly AuditWriter $writer) {}

    /**
     * Record an explicit event instead of the request's own (§5.2). Without
     * $actor the signed-in principal acts.
     *
     * @param  array{type: string, user: ?string, name: ?string}|null|false  $object  false: the route's object
     */
    public function explicit(
        Request $request,
        string $action,
        string $result = 'ok',
        array|null|false $object = false,
        ?string $actor = null,
        Role|string|null $role = null,
        ?string $effectiveUser = null,
    ): void {
        $request = self::base($request);
        $event = ['action' => $action, 'result' => $result, 'object' => $object];
        if ($actor !== null) {
            $event['actor'] = $actor;
        }
        if ($role !== null) {
            $event['role'] = $role instanceof Role ? $role->value : $role;
        }
        if ($effectiveUser !== null) {
            $event['effective_user'] = $effectiveUser;
        }
        $events = (array) $request->attributes->get(self::EVENTS, []);
        $events[] = $event;
        $request->attributes->set(self::EVENTS, $events);
    }

    /** The object when the route parameters do not hold it (a new access key's id). */
    public function objectName(Request $request, string $name, ?string $type = null): void
    {
        self::base($request)->attributes->set(self::NAME, [$name, $type]);
    }

    /** A form request is a copy: its attributes never reach terminate(). */
    private static function base(Request $request): Request
    {
        return $request instanceof FormRequest && app()->bound('request') ? app('request') : $request;
    }

    /** Write the events of a finished request. */
    public function finish(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::DONE) === true) {
            return;
        }
        $request->attributes->set(self::DONE, true);

        $events = (array) $request->attributes->get(self::EVENTS, []);
        if ($events === []) {
            if ($request->isMethodSafe() ? ! RouteActions::isSensitiveRead($request) : $this->isDryRun($request)) {
                return;
            }
            $events[] = ['object' => false];
        }
        foreach ($events as $partial) {
            $this->write($this->event($request, $response, $partial));
        }
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    public function event(Request $request, Response $response, array $partial = []): array
    {
        $principal = app()->bound(Principal::class) ? app(Principal::class) : null;
        $route = $request->route();
        $status = $response->getStatusCode();
        $actor = $partial['actor'] ?? $principal?->loginUser ?? '';
        $object = array_key_exists('object', $partial) ? $partial['object'] : false;
        if ($object === false) {
            $object = ObjectMap::of($route);
            [$name, $type] = (array) $request->attributes->get(self::NAME, [null, null]) + [null, null];
            if (is_string($name) && $name !== '') {
                $object = ['type' => $type ?? $object['type'] ?? 'unknown', 'user' => $object['user'] ?? null, 'name' => mb_substr($name, 0, 255)];
            }
        }
        $ua = (string) $request->userAgent();

        return [
            'v' => 1,
            'id' => (string) Str::ulid(),
            'request_id' => ApiHeaders::requestId($request),
            'channel' => self::isApi($request) ? 'api_v2' : 'web',
            'actor' => [
                'user' => mb_substr((string) $actor, 0, 128),
                'role' => (string) ($partial['role'] ?? ($principal !== null && $actor === $principal->loginUser ? $principal->loginRole->value : '')),
                'ip' => ClientIp::of($request) ?: null,
                'key' => $principal?->accessKey,
                'ua' => $ua === '' ? null : substr(hash('sha256', $ua), 0, 16),
            ],
            'effective_user' => $partial['effective_user'] ?? ($principal !== null && $actor === $principal->loginUser ? $principal->effectiveUser() : ($actor !== '' ? $actor : null)),
            'action' => $partial['action'] ?? RouteActions::action($route?->getName()),
            'object' => $object,
            'result' => $partial['result'] ?? self::result($request, $status, $principal),
            'status' => $status,
            'fields' => self::fields($request),
        ];
    }

    /**
     * ok (2xx, 3xx), denied (401, 403, 404, 405, 419, 429), invalid (other
     * 4xx), failed (5xx). A page form answers a redirect: with validation
     * errors flashed by this request it is invalid, with an error message
     * (a command that failed) failed.
     */
    public static function result(Request $request, int $status, ?Principal $principal): string
    {
        // A guest sent to the login page by auth.arc was refused, not served
        if ($principal === null && $status < 400 && in_array('auth.arc', $request->route()?->gatherMiddleware() ?? [], true)) {
            return 'denied';
        }
        if ($status >= 300 && $status < 400 && $request->hasSession()) {
            // The session is saved before terminate(): this request's flashes are old by then
            $flashed = [...(array) $request->session()->get('_flash.new', []), ...(array) $request->session()->get('_flash.old', [])];
            if (in_array('errors', $flashed, true)) {
                return 'invalid';
            }
            if (in_array('error', $flashed, true)) {
                return 'failed';
            }
        }

        return match (true) {
            $status >= 500 => 'failed',
            in_array($status, [401, 403, 404, 405, 419, 429], true) => 'denied',
            $status >= 400 => 'invalid',
            default => 'ok',
        };
    }

    /**
     * The names of the request's input fields, never their values (D5):
     * the body (JSON or form) and the files, top level only.
     *
     * @return list<string>
     */
    public static function fields(Request $request): array
    {
        if ($request->isMethodSafe()) {
            return [];
        }
        $body = $request->isJson() ? (array) $request->json()->all() : $request->request->all();
        $names = array_merge(array_keys($body), array_keys($request->allFiles()));
        $names = array_filter($names, fn ($name) => is_string($name)
            && ! in_array($name, self::SKIPPED_FIELDS, true)
            && preg_match('/^[A-Za-z0-9_.\[\]-]{1,64}$/', $name) === 1);

        return array_values(array_unique($names));
    }

    private static function isApi(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'api.v2.') || $request->is('api/v2', 'api/v2/*');
    }

    private function isDryRun(Request $request): bool
    {
        return self::isApi($request) && $request->boolean('dry_run');
    }

    /** @param  array<string, mixed>  $event */
    private function write(array $event): void
    {
        try {
            $this->writer->write($event);
        } catch (Throwable $e) {
            $this->failed($event, $e);
        }
    }

    /** @param  array<string, mixed>  $event */
    private function failed(array $event, Throwable $e): void
    {
        try {
            Log::channel('audit')->error('The audit event could not be written.', [
                'id' => $event['id'],
                'action' => $event['action'],
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            if (Cache::add('audit:failure-notice:'.gmdate('Y-m-d'), true, now()->addDay())) {
                $arc = app(ArcCommand::class);
                $arc = $arc instanceof KeyScopedArcCommand ? $arc->inner() : $arc;
                $arc->run('v-add-user-notification', [
                    app(SysConfig::class)->get('ROOT_USER', 'admin'),
                    __('Audit log'),
                    __('An event of the audit log could not be written. The panel log (audit channel) names the error; check the disk of the server.'),
                ], Options::allowFailure());
            }
        } catch (Throwable) {
            // Reporting must never fail the request either
        }
    }
}
