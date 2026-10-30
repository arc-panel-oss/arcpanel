<?php

namespace App\Services\Deploy;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * Git Deploy of a web domain (E20 spec §4, §7,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md): reads the
 * configuration, the releases and the deploy tasks, and runs the commands
 * of the Deploy tab and of REST API v2. Secrets only travel as Secret files.
 */
final class DeployService
{
    /** Keys v-change-web-domain-deploy takes, in the order a form saves them. */
    public const SETTINGS = ['URL', 'BRANCH', 'PRESET', 'WEBROOT', 'SHARED', 'KEEP', 'MIGRATE', 'HEALTH_PATH'];

    public const PRESETS = ['none', 'laravel', 'static', 'custom'];

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * The configuration of the domain (STATE off when deploy is not on).
     *
     * @return array<string, mixed>
     */
    public function config(string $user, string $domain): array
    {
        $data = $this->arc->json('v-list-web-domain-deploy', [$user, $domain]);
        $row = $data[$domain] ?? (is_array(reset($data)) ? reset($data) : []);

        return is_array($row) ? $row : [];
    }

    /**
     * Releases (newest first) and the last deploy tasks (newest first).
     *
     * @return array{releases: list<array<string, string>>, tasks: list<array<string, string>>}
     */
    public function history(string $user, string $domain): array
    {
        $data = $this->arc->json('v-list-web-domain-deploys', [$user, $domain]);

        return [
            'releases' => array_values(array_filter((array) ($data['releases'] ?? []), 'is_array')),
            'tasks' => array_values(array_filter((array) ($data['tasks'] ?? []), 'is_array')),
        ];
    }

    /**
     * Turn on pull mode; the public key and the webhook secret come back once.
     *
     * @return array{public_key: string, webhook_secret: string}
     */
    public function enablePull(string $user, string $domain, string $url, string $branch, string $preset, string $auth, ?string $token, ?string $webroot): array
    {
        $result = $this->arc->run('v-add-web-domain-deploy', [$user, $domain, 'pull', $url, $branch, $preset, $auth]);
        $values = self::values($result);
        try {
            if ($auth === 'token' && $token !== null && $token !== '') {
                $this->arc->run('v-change-web-domain-deploy-credential', [$user, $domain, 'token', Secret::file($token)]);
            }
            if ($webroot !== null && $webroot !== '' && $webroot !== ($this->config($user, $domain)['WEBROOT'] ?? '')) {
                $this->arc->run('v-change-web-domain-deploy', [$user, $domain, 'WEBROOT', $webroot]);
            }
        } catch (ArcCommandException $e) {
            // A half set up deploy is not left behind
            $this->arc->run('v-delete-web-domain-deploy', [$user, $domain], Options::allowFailure());
            throw $e;
        }

        return ['public_key' => $values['PUBLIC_KEY'] ?? '', 'webhook_secret' => $values['WEBHOOK_SECRET'] ?? ''];
    }

    /** Turn on push mode with its first trusted publisher. */
    public function enablePush(string $user, string $domain, ?string $webroot, array $publisher): void
    {
        $this->arc->run('v-add-web-domain-deploy', [$user, $domain, 'push']);
        try {
            if ($webroot !== null && $webroot !== '' && $webroot !== '.') {
                $this->arc->run('v-change-web-domain-deploy', [$user, $domain, 'WEBROOT', $webroot]);
            }
            $this->addPublisher($user, $domain, $publisher);
        } catch (ArcCommandException $e) {
            $this->arc->run('v-delete-web-domain-deploy', [$user, $domain], Options::allowFailure());
            throw $e;
        }
    }

    /**
     * Save the settings that changed; the names of the saved keys.
     *
     * @param  array<string, string>  $current
     * @param  array<string, string>  $wanted
     * @return list<string>
     */
    public function change(string $user, string $domain, array $current, array $wanted): array
    {
        $saved = [];
        foreach (self::SETTINGS as $key) {
            if (! array_key_exists($key, $wanted) || (string) ($current[$key] ?? '') === $wanted[$key]) {
                continue;
            }
            $this->arc->run('v-change-web-domain-deploy', [$user, $domain, $key, $wanted[$key]]);
            $saved[] = $key;
        }

        return $saved;
    }

    /** Start a deploy (pull mode: the branch); the task ID. */
    public function deploy(string $user, string $domain): string
    {
        return self::taskId($this->arc->run('v-schedule-web-domain-deploy', [$user, $domain, '', 'json']));
    }

    /** Roll back to RELEASE; the task ID. */
    public function rollback(string $user, string $domain, string $release): string
    {
        return self::taskId($this->arc->run('v-rollback-web-domain-deploy', [$user, $domain, $release, 'json']));
    }

    /**
     * A new deploy key, webhook secret or ID, or a token; the printed values
     * (PUBLIC_KEY, WEBHOOK_SECRET, ID).
     *
     * @return array<string, string>
     */
    public function renew(string $user, string $domain, string $kind, ?string $token = null): array
    {
        $args = [$user, $domain, $kind];
        if ($kind === 'token') {
            $args[] = Secret::file((string) $token);
        }

        return self::values($this->arc->run('v-change-web-domain-deploy-credential', $args));
    }

    /**
     * @param  array{issuer: string, repo: string, ref?: string|null, environment?: string|null, workflow?: string|null}  $publisher
     */
    public function addPublisher(string $user, string $domain, array $publisher): string
    {
        $ref = trim((string) ($publisher['ref'] ?? ''));
        if ($ref !== '' && ! str_starts_with($ref, 'refs/')) {
            $ref = 'refs/heads/'.$ref;
        }
        $result = $this->arc->run('v-add-web-domain-deploy-publisher', [
            $user, $domain, $publisher['issuer'], $publisher['repo'], $ref,
            trim((string) ($publisher['environment'] ?? '')), trim((string) ($publisher['workflow'] ?? '')), 'json',
        ]);
        $data = json_decode($result->stdout, true);

        return is_array($data) ? (string) ($data['ID'] ?? '') : '';
    }

    public function deletePublisher(string $user, string $domain, string $pub): void
    {
        $this->arc->run('v-delete-web-domain-deploy-publisher', [$user, $domain, $pub]);
    }

    public function disable(string $user, string $domain): void
    {
        $this->arc->run('v-delete-web-domain-deploy', [$user, $domain]);
    }

    /**
     * The server settings (DEPLOY_PORT, DEPLOY_MAX_ARTIFACT, DEPLOY_BUILD_TIMEOUT, DEPLOY_OIDC_ISSUERS, DOMAINS, PORT_OPEN).
     *
     * @return array<string, string>
     */
    public function server(): array
    {
        return array_map('strval', $this->arc->json('v-list-sys-deploy'));
    }

    public function changeServer(string $key, string $value): void
    {
        $this->arc->run('v-change-sys-deploy', [$key, $value]);
    }

    /** The trusted OIDC issuers the server allows, with a label each. */
    public function issuers(): array
    {
        $issuers = [];
        foreach (explode(',', (string) ($this->server()['DEPLOY_OIDC_ISSUERS'] ?? '')) as $issuer) {
            $issuer = rtrim(trim($issuer), '/');
            if ($issuer !== '') {
                $issuers[$issuer] = self::issuerLabel($issuer);
            }
        }

        return $issuers;
    }

    public static function issuerLabel(string $issuer): string
    {
        return match (rtrim($issuer, '/')) {
            'https://token.actions.githubusercontent.com' => 'GitHub Actions',
            'https://gitlab.com' => 'GitLab.com',
            default => (string) (parse_url($issuer, PHP_URL_HOST) ?: $issuer),
        };
    }

    /** Start a deploy of REF (pull mode: a commit or a ref name; empty: the branch); the task ID. */
    public function deployRef(string $user, string $domain, string $ref): string
    {
        return self::taskId($this->arc->run('v-schedule-web-domain-deploy', [$user, $domain, $ref, 'json']));
    }

    /**
     * The arguments of v-add-web-domain-deploy-publisher after USER DOMAIN, as
     * addPublisher() passes them: a bare branch becomes refs/heads/BRANCH.
     *
     * @param  array{issuer: string, repo: string, ref?: string|null, environment?: string|null, workflow?: string|null}  $publisher
     * @return list<string>
     */
    public static function publisherArguments(array $publisher): array
    {
        $ref = trim((string) ($publisher['ref'] ?? ''));
        if ($ref !== '' && ! str_starts_with($ref, 'refs/')) {
            $ref = 'refs/heads/'.$ref;
        }

        return [
            rtrim($publisher['issuer'], '/'), $publisher['repo'], $ref,
            trim((string) ($publisher['environment'] ?? '')), trim((string) ($publisher['workflow'] ?? '')), 'json',
        ];
    }

    /**
     * KEY='value' lines in the output of a command (PUBLIC_KEY, WEBHOOK_SECRET, ID).
     *
     * @return array<string, string>
     */
    public static function printedValues(string $stdout): array
    {
        return self::values(new CommandResult('', [], 0, $stdout));
    }

    /** KEY='value' lines a command printed. */
    private static function values(CommandResult $result): array
    {
        $values = [];
        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if (preg_match("/^([A-Z_]+)='([^']*)'$/", trim($line), $m) === 1) {
                $values[$m[1]] = $m[2];
            }
        }

        return $values;
    }

    private static function taskId(CommandResult $result): string
    {
        $data = json_decode($result->stdout, true);

        return is_array($data) ? (string) ($data['ID'] ?? '') : '';
    }
}
