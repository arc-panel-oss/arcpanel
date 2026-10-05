<?php

namespace App\Http\Controllers\Shell;

use App\Arc\ArcCommand;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The notification tray (docs/design/05 §4.2) for the effective user, as
 * the legacy top bar shows it.
 */
final class NotificationController extends Controller
{
    // The Principal is bound by middleware, after controllers are built:
    // it is injected per method.
    public function __construct(private readonly ArcCommand $arc) {}

    public function index(Principal $principal): JsonResponse
    {
        $notifications = [];
        foreach ($this->arc->json('v-list-user-notifications', [$principal->effectiveUser()]) as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $notifications[] = [
                'id' => (string) $id,
                'title' => self::text((string) ($row['TOPIC'] ?? '')),
                'body' => self::text((string) ($row['NOTICE'] ?? '')),
                'acknowledged' => ($row['ACK'] ?? '') === 'yes',
                'date' => (string) ($row['DATE'] ?? ''),
                'time' => (string) ($row['TIME'] ?? ''),
            ];
        }

        return response()->json(['notifications' => array_reverse($notifications)]);
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

    /** Notices may hold HTML (links); the tray shows plain text. */
    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
