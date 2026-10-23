<?php

namespace App\Audit;

/**
 * Writes one audit event (E23 spec §3) to the log of the server. Throws
 * AuditWriteFailed when it cannot; the recorder reports that (D11).
 */
interface AuditWriter
{
    /** @param  array<string, mixed>  $event */
    public function write(array $event): void;
}
