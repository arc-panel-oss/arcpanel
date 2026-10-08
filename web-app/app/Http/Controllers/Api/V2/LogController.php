<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\AuthLogEntryResource;
use App\Http\Resources\V2\LogEntryResource;
use App\Policies\LogPolicy;
use App\Services\Logs\LogService;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The action log and the login history of an account (E34 batch 6 §4.1),
 * with the rules of the Logs page (LogPolicy): administrators read and
 * clear every log; everyone else follows POLICY_USER_VIEW_LOGS and
 * POLICY_USER_DELETE_LOGS, and resellers read the logs of their customers
 * without clearing them. The login history is hidden in demo mode, and the
 * root administrator's is its own under POLICY_SYSTEM_PROTECTED_ADMIN.
 *
 * @tags Logs
 */
final class LogController extends Controller
{
    /** Entries a page may skip: offset + limit + 1 stays within the 10000 lines of the commands (D60). */
    public const MAX_OFFSET = 8999;

    public function __construct(
        private readonly LogService $logs,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * List the action log of a user
     *
     * Newest first: `limit` entries (default 100) after skipping `offset`
     * newer ones; `meta.more` says whether older entries exist.
     *
     * @operationId listUserLogs
     */
    public function index(Request $request, string $user, Principal $principal, LogPolicy $policy): AnonymousResourceCollection
    {
        $this->assertExists($user);
        abort_unless($policy->view($principal, $user), 403);
        ['limit' => $limit, 'offset' => $offset] = self::window($request);
        $rows = $this->logs->latestActions($user, $offset + $limit + 1);

        return LogEntryResource::collection(array_slice($rows, $offset, $limit))
            ->additional(['meta' => self::meta($rows, $limit, $offset)]);
    }

    /**
     * Clear the action log of a user
     *
     * Administrators clear any log; users and resellers their own when
     * POLICY_USER_DELETE_LOGS allows it.
     *
     * @operationId clearUserLogs
     *
     * @status 204
     */
    public function destroy(string $user, Principal $principal, LogPolicy $policy): Response|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->delete($principal, $user), 403);

        return $this->gate->execute([new Step(__('Delete the logs'), 'v-delete-user-log', [$user])], $this->runner->apply(...))
            ?? response()->noContent();
    }

    /**
     * List the login history of a user
     *
     * Newest first, paged like the action log. Not available in demo mode.
     *
     * @operationId listUserAuthLogs
     */
    public function logins(Request $request, string $user, Principal $principal, LogPolicy $policy): AnonymousResourceCollection
    {
        $this->assertExists($user);
        abort_unless($policy->viewAuth($principal, $user), 403);
        ['limit' => $limit, 'offset' => $offset] = self::window($request);
        $rows = $this->logs->latestLogins($user, $offset + $limit + 1);

        return AuthLogEntryResource::collection(array_slice($rows, $offset, $limit))
            ->additional(['meta' => self::meta($rows, $limit, $offset)]);
    }

    /**
     * Clear the login history of a user
     *
     * @operationId clearUserAuthLogs
     *
     * @status 204
     */
    public function destroyLogins(string $user, Principal $principal, LogPolicy $policy): Response|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->deleteAuth($principal, $user), 403);

        return $this->gate->execute([new Step(__('Delete the login history'), 'v-delete-user-auth-log', [$user])], $this->runner->apply(...))
            ?? response()->noContent();
    }

    /** @return array{limit: int, offset: int} */
    private static function window(Request $request): array
    {
        $query = $request->validate([
            /** Entries to return, from 1 to 1000. Defaults to 100. */
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            /** Newer entries to skip, from 0 to 8999. */
            'offset' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_OFFSET],
        ]);

        return ['limit' => (int) ($query['limit'] ?? 100), 'offset' => (int) ($query['offset'] ?? 0)];
    }

    /**
     * @param  list<mixed>  $rows  the newest offset + limit + 1 entries
     * @return array{limit: int, offset: int, more: bool}
     */
    private static function meta(array $rows, int $limit, int $offset): array
    {
        return ['limit' => $limit, 'offset' => $offset, 'more' => count($rows) > $offset + $limit];
    }

    /** An account the principal reaches but that does not exist answers 404 (administrators reach every name). */
    private function assertExists(string $user): void
    {
        abort_unless(array_key_exists($user, $this->directory->all()), 404);
    }
}
