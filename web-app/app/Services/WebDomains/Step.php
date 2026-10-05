<?php

namespace App\Services\WebDomains;

use App\Arc\Secret;
use Closure;

/**
 * One command of a planned change: what the user is told it does, the v-*
 * call, the services to restart once the whole change succeeded, and an
 * optional action after it succeeded (e.g. send FTP credentials).
 */
final class Step
{
    /**
     * @param  list<string|int|Secret>  $args
     * @param  list<'web'|'proxy'|'dns'>  $restart
     */
    public function __construct(
        public readonly string $label,
        public readonly string $command,
        public readonly array $args,
        public readonly array $restart = [],
        public readonly ?Closure $onSuccess = null,
    ) {}
}
