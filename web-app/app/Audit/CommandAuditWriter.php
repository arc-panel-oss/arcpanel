<?php

namespace App\Audit;

use App\Api\KeyScopedArcCommand;
use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * `v-add-sys-audit-event FILE` (E23 spec D2): the event goes to the command
 * in a 0600 temporary file and the command chains and appends it as root.
 * It runs outside the permission sets of an access key, as the request
 * journal does: the key of the request is what is being recorded.
 */
final class CommandAuditWriter implements AuditWriter
{
    public function write(array $event): void
    {
        $arc = app(ArcCommand::class);
        if ($arc instanceof KeyScopedArcCommand) {
            $arc = $arc->inner();
        }
        $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $result = $arc->run('v-add-sys-audit-event', [Secret::file($json)], Options::allowFailure());
        if (! $result->ok()) {
            throw new AuditWriteFailed(trim($result->stdout.' '.$result->stderr) ?: 'v-add-sys-audit-event failed');
        }
    }
}
