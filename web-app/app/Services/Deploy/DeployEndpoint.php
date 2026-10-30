<?php

namespace App\Services\Deploy;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;

/**
 * What the deploy port needs to know about a deploy ID (E20 spec §6,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md): the owner
 * and the domain, and the webhook secret (pull) or the trusted publishers
 * with the allowed issuers and the audience (push). An unknown ID, a
 * suspended domain or a package without GIT_DEPLOY all read as null.
 */
final class DeployEndpoint
{
    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * @param  'hook'|'upload'  $kind
     * @return array{USER: string, DOMAIN: string, MODE: string, BRANCH: string, STATE: string, SECRET: string, PUBLISHERS: list<array<string, string>>, ISSUERS: list<string>, AUDIENCE: string}|null
     */
    public function find(string $id, string $kind): ?array
    {
        if (preg_match('/^[a-z0-9]{12}$/', $id) !== 1) {
            return null;
        }
        $result = $this->arc->run('v-get-web-domain-deploy-endpoint', [$id, $kind], Options::allowFailure());
        if (! $result->ok()) {
            return null;
        }
        $data = json_decode($result->stdout, true);
        if (! is_array($data) || (string) ($data['USER'] ?? '') === '' || (string) ($data['DOMAIN'] ?? '') === '') {
            return null;
        }

        return [
            'USER' => (string) $data['USER'],
            'DOMAIN' => (string) $data['DOMAIN'],
            'MODE' => (string) ($data['MODE'] ?? ''),
            'BRANCH' => (string) ($data['BRANCH'] ?? ''),
            'STATE' => (string) ($data['STATE'] ?? ''),
            'SECRET' => (string) ($data['SECRET'] ?? ''),
            'PUBLISHERS' => array_values(array_filter((array) ($data['PUBLISHERS'] ?? []), 'is_array')),
            'ISSUERS' => array_values(array_map('strval', (array) ($data['ISSUERS'] ?? []))),
            'AUDIENCE' => (string) ($data['AUDIENCE'] ?? ''),
        ];
    }

    /** Start a deploy of REF (a commit, or the branch when empty); the task ID or null. */
    public function deployRef(string $user, string $domain, string $ref): ?string
    {
        return $this->taskOf($this->arc->run('v-schedule-web-domain-deploy', [$user, $domain, $ref, 'json'], Options::allowFailure()));
    }

    /** Start a deploy of the archive FILE; the task ID or null. */
    public function deployArchive(string $user, string $domain, string $file): ?string
    {
        return $this->taskOf($this->arc->run('v-schedule-web-domain-deploy', [$user, $domain, '--artifact', $file, 'json'], Options::allowFailure()));
    }

    private function taskOf(CommandResult $result): ?string
    {
        if (! $result->ok()) {
            return null;
        }
        $data = json_decode($result->stdout, true);
        $id = is_array($data) ? (string) ($data['ID'] ?? '') : '';

        return preg_match('/^\d{14}$/', $id) === 1 ? $id : null;
    }
}
