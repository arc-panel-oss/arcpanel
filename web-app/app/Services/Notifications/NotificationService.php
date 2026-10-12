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
     * Newest first, with plain text title and body; parts holds the body as
     * text runs and links for the tray.
     *
     * @return list<array{id: string, title: string, body: string, parts: list<array{text: string, url: string|null, external: bool}>, acknowledged: bool, date: string, time: string}>
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
                'parts' => self::parts((string) ($row['NOTICE'] ?? '')),
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

    /**
     * The notice as text runs and links, in order, for the tray. Only http(s)
     * and same-site links are kept; any other target becomes plain text.
     *
     * @return list<array{text: string, url: string|null, external: bool}>
     */
    public static function parts(string $html): array
    {
        $parts = [];
        $offset = 0;
        preg_match_all('/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a\s*>/is', $html, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($links as $link) {
            $parts[] = ['text' => self::inline(substr($html, $offset, $link[0][1] - $offset)), 'url' => null, 'external' => false];
            $url = html_entity_decode($link[2][0], ENT_QUOTES | ENT_HTML5);
            $external = preg_match('#^https?://[^\s]+$#i', $url) === 1;
            $safe = $external || preg_match('#^/(?![/\\\\])\S*$#', $url) === 1;
            $parts[] = ['text' => self::inline($link[3][0]), 'url' => $safe ? $url : null, 'external' => $safe && $external];
            $offset = $link[0][1] + strlen($link[0][0]);
        }
        $parts[] = ['text' => self::inline(substr($html, $offset)), 'url' => null, 'external' => false];

        // One space between runs at most, none at the ends
        $out = [];
        $space = true;
        foreach ($parts as $part) {
            $text = $space ? ltrim($part['text']) : $part['text'];
            if ($text === '') {
                continue;
            }
            $space = str_ends_with($text, ' ');
            $previous = array_key_last($out);
            if ($part['url'] === null && $previous !== null && $out[$previous]['url'] === null) {
                $out[$previous]['text'] .= $text;
            } else {
                $out[] = ['text' => $text, 'url' => $part['url'], 'external' => $part['external']];
            }
        }
        if ($out !== []) {
            $last = array_key_last($out);
            $out[$last]['text'] = rtrim($out[$last]['text']);
            if ($out[$last]['text'] === '') {
                array_pop($out);
            }
        }

        return $out;
    }

    /** Notices may hold HTML (paragraphs, links); the plain text of it. */
    private static function text(string $html): string
    {
        return implode('', array_column(self::parts($html), 'text'));
    }

    /** Plain text of an HTML fragment: block ends and line breaks become spaces. */
    private static function inline(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)\b[^>]*>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
