<?php

namespace App\Services\Support;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\SysConfig;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;

/**
 * Runs a planned change (a list of Steps) in order. The first failure stops
 * the run; steps before it stay applied, as in the legacy edit pages. The
 * services named by the steps are restarted once at the end, only when every
 * step succeeded.
 */
final class PlanRunner
{
    /** Restart key of a Step => command. */
    private const RESTARTS = [
        'web' => 'v-restart-web',
        'proxy' => 'v-restart-proxy',
        'dns' => 'v-restart-dns',
        'cron' => 'v-restart-cron',
        'mail' => 'v-restart-mail',
    ];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    /**
     * The restart commands apply() may run after $steps.
     *
     * @param  list<Step>  $steps
     * @return list<string>
     */
    public static function restartCommands(array $steps): array
    {
        $services = array_unique(array_merge([], ...array_map(fn (Step $step) => $step->restart, $steps)));

        return array_values(array_intersect_key(self::RESTARTS, array_flip($services)));
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $applied = [];
        $stdoutByCommand = [];
        $restart = [];
        foreach ($steps as $step) {
            try {
                $result = $this->arc->run($step->command, $step->args);
                $stdoutByCommand[$step->command] = $result->stdout;
            } catch (ArcCommandException $e) {
                return new ApplyResult($applied, $step, $e->userMessage(), $e->getCode(), $stdoutByCommand, $e->invalidArgument);
            }
            $applied[] = $step;
            foreach ($step->restart as $service) {
                $restart[$service] = true;
            }
            if ($step->onSuccess !== null) {
                ($step->onSuccess)();
            }
        }

        foreach (self::RESTARTS as $service => $command) {
            if (! isset($restart[$service]) || ($service === 'proxy' && $this->config->get('PROXY_SYSTEM') === '')) {
                continue;
            }
            try {
                $this->arc->run($command);
            } catch (ArcCommandException $e) {
                return new ApplyResult($applied, new Step(self::label($service), $command, []), $e->userMessage(), stdoutByCommand: $stdoutByCommand);
            }
        }

        return new ApplyResult($applied, stdoutByCommand: $stdoutByCommand);
    }

    private static function label(string $service): string
    {
        return match ($service) {
            'web' => __('Restart the web server'),
            'proxy' => __('Restart the proxy server'),
            'dns' => __('Restart the DNS server'),
            'cron' => __('Restart the cron service'),
            default => __('Restart the mail server'),
        };
    }
}
