<?php

namespace App\Http\Controllers\Deploy;

use App\Api\TaskId;
use App\Http\Controllers\Controller;
use App\Services\Deploy\DeployEndpoint;
use App\Services\Deploy\Oidc\OidcRejected;
use App\Services\Deploy\Oidc\OidcVerifier;
use App\Services\Deploy\WebhookEvent;
use App\Services\Deploy\WebhookRejected;
use App\Services\Deploy\WebhookSignature;
use App\Services\Tasks\TaskService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Git Deploy endpoint (E20 spec §6,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md). The system
 * NGINX serves it on DEPLOY_PORT and proxies three paths here, outside the
 * web group: no session, no cookie, no CSRF token. Every answer is short
 * JSON; nothing tells an unknown ID from a domain that cannot deploy.
 */
final class DeployEndpointController extends Controller
{
    /** Seconds an upload's publisher may read its task (§6.3 step 6). */
    private const STATUS_SECONDS = 900;

    public function __construct(
        private readonly DeployEndpoint $endpoint,
        private readonly Repository $cache,
    ) {}

    /** POST /hooks/{id}: a push event of pull mode (§6.2). */
    public function hook(Request $request, string $id, WebhookSignature $signature): JsonResponse
    {
        $deploy = $this->endpoint->find($id, 'hook');
        if ($deploy === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        try {
            $event = $signature->verify($request->headers->all(), $request->getContent(), $deploy['SECRET']);
        } catch (WebhookRejected) {
            Log::warning('Git Deploy webhook refused: signature', ['id' => $id, 'ip' => $this->clientIp($request)]);

            return response()->json(['error' => 'signature'], 401);
        }

        if ($event->kind === WebhookEvent::KIND_PING) {
            return response()->json(['ok' => true]);
        }
        if ($event->kind !== WebhookEvent::KIND_PUSH) {
            return response()->json(['skipped' => 'event'], 202);
        }
        if ($event->branch() !== $deploy['BRANCH']) {
            return response()->json(['skipped' => 'branch'], 202);
        }

        $task = $this->endpoint->deployRef($deploy['USER'], $deploy['DOMAIN'], $event->commit ?? '');
        if ($task === null) {
            return response()->json(['error' => 'not started'], 409);
        }

        return response()->json(['task' => $task], 202);
    }

    /** POST /upload/{id}: an archive built by a trusted publisher (§6.3). */
    public function upload(Request $request, string $id, OidcVerifier $verifier): JsonResponse
    {
        $deploy = $this->endpoint->find($id, 'upload');
        if ($deploy === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        try {
            $verified = $verifier->verify((string) $request->bearerToken(), $deploy['AUDIENCE'], $deploy['ISSUERS'], $deploy['PUBLISHERS']);
        } catch (OidcRejected $e) {
            $this->logRefusal($request, $id, $e);

            return response()->json(['error' => $e->reason], 401);
        }

        $file = $this->store($request);
        if ($file === null) {
            return response()->json(['error' => 'upload'], 400);
        }
        try {
            $task = $this->endpoint->deployArchive($deploy['USER'], $deploy['DOMAIN'], $file);
        } finally {
            @unlink($file);
        }
        if ($task === null) {
            return response()->json(['error' => 'not started'], 409);
        }

        $this->cache->put($this->statusKey($id, $task), [
            'issuer' => $verified->issuer, 'subject' => $verified->subject, 'publisher' => $verified->publisher,
        ], self::STATUS_SECONDS);
        $location = preg_replace('#/upload/[a-z0-9]{12}$#', '/status/'.$id.'/'.$task, $deploy['AUDIENCE']);

        return response()->json(['task' => $task, 'status_url' => $location], 202, ['Location' => $location]);
    }

    /** GET /status/{id}/{task}: the state of an upload, for its publisher only. */
    public function status(Request $request, string $id, string $task, OidcVerifier $verifier, TaskService $tasks): JsonResponse
    {
        $deploy = $this->endpoint->find($id, 'upload');
        if ($deploy === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        try {
            $verified = $verifier->verify((string) $request->bearerToken(), $deploy['AUDIENCE'], $deploy['ISSUERS'], $deploy['PUBLISHERS'], checkReplay: false);
        } catch (OidcRejected $e) {
            return response()->json(['error' => $e->reason], 401);
        }

        $grant = $this->cache->get($this->statusKey($id, $task));
        if (! is_array($grant) || $grant['issuer'] !== $verified->issuer || $grant['subject'] !== $verified->subject
            || $grant['publisher'] !== $verified->publisher) {
            return response()->json(['error' => 'not found'], 404);
        }

        try {
            $view = $tasks->get(TaskId::queue($deploy['USER'], $task), 20)->toArray();
        } catch (Throwable) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->json([
            'task' => $task,
            'status' => $view['status'],
            'error' => $view['error'],
            'started_at' => $view['started_at'],
            'finished_at' => $view['finished_at'],
        ]);
    }

    /** The body in a 0600 file of the panel's storage, or null. */
    private function store(Request $request): ?string
    {
        $dir = storage_path('app/deploy-uploads');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return null;
        }
        $file = $dir.'/'.bin2hex(random_bytes(12)).'.tar.gz';
        $out = @fopen($file, 'xb');
        if ($out === false) {
            return null;
        }
        chmod($file, 0600);
        $in = $request->getContent(true);
        $bytes = is_resource($in) ? stream_copy_to_stream($in, $out) : false;
        fclose($out);
        if ($bytes === false || $bytes === 0) {
            @unlink($file);

            return null;
        }

        return $file;
    }

    private function statusKey(string $id, string $task): string
    {
        return 'deploy.status.'.$id.'.'.$task;
    }

    /** The refusal and the claims, never the token (§6.3). */
    private function logRefusal(Request $request, string $id, OidcRejected $e): void
    {
        $claims = array_intersect_key($e->claims, array_flip(['iss', 'aud', 'sub', 'repository', 'ref', 'environment', 'job_workflow_ref', 'project_path', 'exp', 'jti']));
        Log::warning('Git Deploy upload refused: '.$e->reason, ['id' => $id, 'ip' => $this->clientIp($request), 'claims' => $claims]);
    }

    /** The CI's address as the deploy port saw it. */
    private function clientIp(Request $request): string
    {
        $forwarded = trim(explode(',', (string) $request->header('X-Forwarded-For', ''))[0]);

        return in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true) && filter_var($forwarded, FILTER_VALIDATE_IP)
            ? $forwarded : (string) $request->server('REMOTE_ADDR', '');
    }
}
