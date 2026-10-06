<?php

namespace App\Http\Controllers;

use App\Arc\ArcCommand;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * GET /app/_health: the panel app boots and can run v-* commands through sudo.
 * No details on failure (the endpoint needs no login).
 */
final class HealthController extends Controller
{
    public function __invoke(ArcCommand $arc): JsonResponse
    {
        try {
            $config = $arc->json('v-list-sys-config')['config'] ?? [];
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'error'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => (string) ($config['VERSION'] ?? ''),
        ]);
    }
}
