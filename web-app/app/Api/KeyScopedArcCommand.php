<?php

namespace App\Api;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\RunsJsonCommands;

/**
 * ArcCommand of an API v2 request whose key has permission sets: a command
 * outside the sets is refused before it runs (E34 spec D5). Plans are also
 * checked as a whole before their first step (PlanGate); this is the guard
 * for everything else an endpoint runs.
 */
final class KeyScopedArcCommand implements ArcCommand
{
    use RunsJsonCommands;

    public function __construct(
        private readonly ArcCommand $inner,
        private readonly KeyPermissions $permissions,
    ) {}

    public function run(string $command, array $args = [], ?Options $options = null): CommandResult
    {
        if (! $this->permissions->allows($command)) {
            throw ApiException::commandsRefused([$command]);
        }

        return $this->inner->run($command, $args, $options);
    }

    /** The unrestricted ArcCommand underneath. */
    public function inner(): ArcCommand
    {
        return $this->inner;
    }
}
