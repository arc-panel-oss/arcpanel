<?php

namespace App\Http\Controllers\Shell;

use App\Arc\ArcCommand;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;

/**
 * The notification tray (docs/design/05 §4.2) for the effective user, as
 * the legacy top bar shows it. Acknowledging and deleting are changes: a
 * read-only session may not make them (E34 batch 6 D64).
 */
final class NotificationController extends Controller
{
    // The Principal is bound by middleware, after controllers are built:
    // it is injected per method.
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Principal $principal): JsonResponse
    {
        return response()->json(['notifications' => $this->notifications->forUser($principal->effectiveUser())]);
    }

    public function acknowledge(Principal $principal, string $id): JsonResponse
    {
        $this->arc->run('v-acknowledge-user-notification', [$principal->effectiveUser(), $id]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Principal $principal, string $id): JsonResponse
    {
        $this->arc->run('v-delete-user-notification', [$principal->effectiveUser(), $id]);

        return response()->json(['ok' => true]);
    }

    /** POST /_notifications/acknowledge: every notification (E7 batch 2 spec §9.3). */
    public function acknowledgeAll(Principal $principal): JsonResponse
    {
        $this->arc->run('v-acknowledge-user-notification', [$principal->effectiveUser(), 'all']);

        return response()->json(['ok' => true]);
    }

    /** DELETE /_notifications: every notification (E7 batch 2 spec §9.3). */
    public function destroyAll(Principal $principal): JsonResponse
    {
        $this->arc->run('v-delete-user-notification', [$principal->effectiveUser(), 'all']);

        return response()->json(['ok' => true]);
    }
}
