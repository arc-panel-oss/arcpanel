<?php

namespace App\Services\Support;

use App\Arc\ArcCommand;
use App\Arc\Options;
use Closure;

/**
 * Runs one command per selected item (the legacy web/bulk/* pages): every
 * item runs even when an earlier one failed, each with RESTART=no where the
 * command takes it, and the services are restarted once at the end. The
 * caller authorises every item before calling run().
 */
final class BulkRunner
{
    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  Closure(T): array{0: string, 1: string, 2: list<string|int>}  $call  label, command and arguments of an item
     * @param  list<string>  $restart  commands run once when at least one item was given
     * @param  (Closure(T): void)|null  $after  runs after each item that succeeded
     */
    public function run(array $items, Closure $call, array $restart = [], ?Closure $after = null): BulkResult
    {
        $done = [];
        $failed = [];
        foreach ($items as $item) {
            [$label, $command, $args] = $call($item);
            $result = $this->arc->run($command, $args, Options::allowFailure());
            if ($result->ok()) {
                $done[] = $label;
                if ($after !== null) {
                    $after($item);
                }
            } else {
                $failed[$label] = $result->message() !== '' ? $result->message() : sprintf(__('Error code: %s'), $result->exitCode);
            }
        }

        if ($items !== []) {
            foreach ($restart as $command) {
                $this->arc->run($command, [], Options::allowFailure());
            }
        }

        return new BulkResult($done, $failed);
    }
}
