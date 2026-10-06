<?php

namespace App\Services\WebDomains;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Services\Node\NodeAccount;
use App\Services\Server\SystemPackages;

/**
 * Whether the runtime of a template is there for a domain owner (E5 spec
 * 2026-10-06-006 D13): PHP always; Node.js when the owner's E13 runtimes are
 * enabled and one is installed; Docker when the engine is installed; Python,
 * Go and Ruby when a system package that provides them is installed. Each
 * answer is read once per request.
 */
final class RuntimeAvailability
{
    /** Runtimes that system packages provide. */
    public const SYSTEM = ['python', 'go', 'ruby'];

    /** @var array<string, bool> */
    private array $known = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly SystemPackages $packages,
    ) {}

    /** Null for `none` and unknown runtimes: they get no badge state. */
    public function available(string $runtime, string $owner): ?bool
    {
        $key = $runtime === 'node' ? "node:$owner" : $runtime;

        return match (true) {
            $runtime === 'php' => true,
            $runtime === 'node', $runtime === 'docker', in_array($runtime, self::SYSTEM, true) => $this->known[$key] ??= $this->read($runtime, $owner),
            default => null,
        };
    }

    private function read(string $runtime, string $owner): bool
    {
        if ($runtime === 'docker') {
            return $this->config->get('DOCKER_SYSTEM') === 'yes';
        }
        if ($runtime === 'node') {
            $account = NodeAccount::from($this->arc->json('v-list-user-node-versions', [$owner], Options::allowFailure()));

            return $account->enabled && $account->runtimes !== [];
        }

        return in_array($runtime, $this->packages->installedRuntimes(), true);
    }
}
