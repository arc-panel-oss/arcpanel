<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\NotificationResource;
use App\Services\Notifications\NotificationService;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The notifications of the key's owner (E34 batch 6 §4.3). Acknowledging
 * and deleting are changes: a read-only key gets 403 (D64).
 *
 * @tags Notifications
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly Paginates $pages,
    ) {}

    /**
     * List notifications
     *
     * The notifications of the key's owner, newest first, as plain text.
     *
     * @operationId listNotifications
     */
    public function index(Request $request, Principal $principal): AnonymousResourceCollection
    {
        $page = $this->pages->slice($request, $this->notifications->forUser($principal->effectiveUser()));

        return NotificationResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Acknowledge a notification
     *
     * @operationId acknowledgeNotification
     *
     * @status 204
     */
    public function acknowledge(string $id, Principal $principal): Response|JsonResponse
    {
        $user = $principal->effectiveUser();
        $this->assertExists($user, $id);

        return $this->run(NotificationService::acknowledge($user, $id));
    }

    /**
     * Acknowledge every notification
     *
     * @operationId acknowledgeAllNotifications
     *
     * @status 204
     */
    public function acknowledgeAll(Principal $principal): Response|JsonResponse
    {
        return $this->run(NotificationService::acknowledge($principal->effectiveUser(), 'all'));
    }

    /**
     * Delete a notification
     *
     * @operationId deleteNotification
     *
     * @status 204
     */
    public function destroy(string $id, Principal $principal): Response|JsonResponse
    {
        $user = $principal->effectiveUser();
        $this->assertExists($user, $id);

        return $this->run(NotificationService::delete($user, $id));
    }

    /**
     * Delete every notification
     *
     * @operationId deleteAllNotifications
     *
     * @status 204
     */
    public function destroyAll(Principal $principal): Response|JsonResponse
    {
        return $this->run(NotificationService::delete($principal->effectiveUser(), 'all'));
    }

    private function run(Step $step): Response|JsonResponse
    {
        return $this->gate->execute([$step], $this->runner->apply(...)) ?? response()->noContent();
    }

    private function assertExists(string $user, string $id): void
    {
        $ids = array_column($this->notifications->forUser($user), 'id');
        abort_unless(in_array($id, $ids, true), 404);
    }
}
