<?php

namespace App\Services\Support;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads one object with a `v-list-<object> USER … json` command: a missing
 * object (exit 1, 2 or 3, or no entry under $key) answers 404, any other
 * failure is an ArcCommandException.
 */
final class ObjectLookup
{
    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * @param  list<string|int>  $args  without the trailing json
     * @param  string|null  $key  the entry of the JSON object; null for the first one
     * @return array<string, mixed>
     */
    public function one(string $command, array $args, ?string $key = null): array
    {
        $data = $this->decode($command, $args);
        $row = $key === null ? reset($data) : ($data[$key] ?? null);
        if (! is_array($row)) {
            throw new NotFoundHttpException;
        }

        return $row;
    }

    /**
     * The whole JSON answer of a list command for one object (records of a
     * zone, accounts of a mail domain …); 404 when the object is missing.
     *
     * @param  list<string|int>  $args
     * @return array<mixed>
     */
    public function decode(string $command, array $args): array
    {
        $result = $this->arc->run($command, [...$args, 'json'], Options::allowFailure());
        if (in_array($result->exit(), [ExitCode::NotExist, ExitCode::Args, ExitCode::Invalid], true)) {
            throw new NotFoundHttpException;
        }
        if (! $result->ok()) {
            throw ArcCommandException::failed($result);
        }
        $data = json_decode($result->stdout, true);
        if (! is_array($data)) {
            throw new NotFoundHttpException;
        }

        return $data;
    }
}
