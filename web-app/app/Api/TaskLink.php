<?php

namespace App\Api;

/**
 * `Link: </api/v2/tasks/{id}>; rel="monitor"` on every 202 (E34 batch 3 D37).
 */
final class TaskLink
{
    public static function monitor(string $apiTaskId): string
    {
        return '</api/v2/tasks/'.$apiTaskId.'>; rel="monitor"';
    }

    public static function queue(string $user, string $recordId): string
    {
        return self::monitor(TaskId::queue($user, $recordId)->apiId());
    }
}
