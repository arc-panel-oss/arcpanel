<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\JsonResponse;

/** GET /app/server/task: the system task as JSON for apTask (E7 batch 3 spec §6.2). */
final class SystemTaskController extends Controller
{
    public function __invoke(Principal $principal, ServerPolicy $policy, SystemTaskService $tasks): JsonResponse
    {
        abort_unless($policy->viewSettings($principal) || $policy->viewUpdates($principal), 403);

        return response()->json(['task' => $tasks->task()])->header('Cache-Control', 'no-store');
    }
}
