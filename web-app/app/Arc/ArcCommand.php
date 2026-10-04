<?php

namespace App\Arc;

/**
 * The only way the panel app talks to the system: runs `sudo v-*` commands.
 *
 * Arguments are strings, ints, Stringables or Secrets. A non-zero exit throws
 * ArcCommandException unless Options::$allowFailure is set.
 */
interface ArcCommand
{
    /** @param  list<string|int|\Stringable|Secret>  $args */
    public function run(string $command, array $args = [], ?Options $options = null): CommandResult;

    /**
     * run() with the `json` format argument appended, output decoded.
     *
     * @param  list<string|int|\Stringable|Secret>  $args
     * @return array<mixed>
     */
    public function json(string $command, array $args = [], ?Options $options = null): array;
}
