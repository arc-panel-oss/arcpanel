<?php

namespace App\Services\Notifications;

use App\Arc\ArcCommand;
use App\Services\WebDomains\Step;

/**
 * The notifications of an account (`v-list-user-notifications`), as the
 * tray of the panel and API v2 (E34 batch 6 §4.3) show them, and the steps
 * that acknowledge or delete them.
 */
final class NotificationService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * Newest first, with plain text title and body.
     *
     * @return list<array{id: string, title: string, body: string, acknowledged: bool, date: string, time: string}>
     */
    public function forUser(string $user): array
    {
        $notifications = [];
        foreach ($this->arc->json('v-list-user-notifications', [$user]) as $id => $row) {
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

        return array_reverse($notifications);
    }

    /** `v-acknowledge-user-notification USER ID|all` */
    public static function acknowledge(string $user, string $id): Step
    {
        return new Step($id === 'all' ? __('Mark all notifications read') : sprintf(__('Mark notification %s read'), $id),
            'v-acknowledge-user-notification', [$user, $id]);
    }

    /** `v-delete-user-notification USER ID|all` */
    public static function delete(string $user, string $id): Step
    {
        return new Step($id === 'all' ? __('Delete all notifications') : sprintf(__('Delete notification %s'), $id),
            'v-delete-user-notification', [$user, $id]);
    }

    /** Notices may hold HTML (links); the tray shows plain text. */
    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
