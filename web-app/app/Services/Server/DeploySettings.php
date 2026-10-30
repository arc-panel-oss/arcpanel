<?php

namespace App\Services\Server;

use App\Arc\ArcCommandException;
use App\Services\Deploy\DeployService;
use App\Services\WebDomains\Step;

/**
 * The Git Deploy card of Server settings → Web (E20 spec §3.4, §7,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md): reads
 * `v-list-sys-deploy` and plans one `v-change-sys-deploy` step per changed
 * key. The build timeout is shown in minutes; the command keeps seconds.
 */
final class DeploySettings
{
    /** The issuers a fresh server trusts; the card labels them and keeps them. */
    public const BUILT_IN_ISSUERS = ['https://token.actions.githubusercontent.com', 'https://gitlab.com'];

    /** What v-change-sys-deploy accepts for an issuer. */
    public const ISSUER_PATTERN = '/^https:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?(\/[A-Za-z0-9._~\/-]*)?$/';

    public function __construct(private readonly DeployService $deploy) {}

    /**
     * DEPLOY_PORT, DEPLOY_MAX_ARTIFACT, DEPLOY_BUILD_TIMEOUT,
     * DEPLOY_OIDC_ISSUERS, DOMAINS and PORT_OPEN; null when the server
     * cannot list them.
     *
     * @return array<string, string>|null
     */
    public function server(): ?array
    {
        try {
            return $this->deploy->server() + [
                'DEPLOY_PORT' => '', 'DEPLOY_MAX_ARTIFACT' => '512M', 'DEPLOY_BUILD_TIMEOUT' => '900',
                'DEPLOY_OIDC_ISSUERS' => '', 'DOMAINS' => '0', 'PORT_OPEN' => 'no',
            ];
        } catch (ArcCommandException) {
            return null;
        }
    }

    /** The build timeout as the card shows it, in whole minutes. */
    public static function minutes(string $seconds): int
    {
        return (int) round(((int) $seconds) / 60);
    }

    /** @return list<string> the issuers of a DEPLOY_OIDC_ISSUERS value, without a trailing slash */
    public static function issuers(string $list): array
    {
        $issuers = [];
        foreach (explode(',', $list) as $issuer) {
            $issuer = rtrim(trim($issuer), '/');
            if ($issuer !== '' && ! in_array($issuer, $issuers, true)) {
                $issuers[] = $issuer;
            }
        }

        return $issuers;
    }

    public static function builtIn(string $issuer): bool
    {
        return in_array(rtrim($issuer, '/'), self::BUILT_IN_ISSUERS, true);
    }

    /** @return list<string> the issuers the administrator added, which the card can remove */
    public static function removable(array $server): array
    {
        return array_values(array_filter(self::issuers($server['DEPLOY_OIDC_ISSUERS'] ?? ''), fn (string $issuer) => ! self::builtIn($issuer)));
    }

    /**
     * The steps of the card's form: a timeout whose minutes did not change
     * keeps its seconds, and a new issuer is appended to the list.
     *
     * @param  array<string, string>  $server
     * @param  array{port: string, max_artifact: string, timeout: int, new_issuer: ?string}  $values
     * @return list<Step>
     */
    public function plan(array $server, array $values): array
    {
        $issuers = $server['DEPLOY_OIDC_ISSUERS'] ?? '';
        $list = self::issuers($issuers);
        if ($values['new_issuer'] !== null && ! in_array($values['new_issuer'], $list, true)) {
            $issuers = implode(',', [...$list, $values['new_issuer']]);
        }
        $timeout = $values['timeout'] === self::minutes($server['DEPLOY_BUILD_TIMEOUT'] ?? '')
            ? (string) ($server['DEPLOY_BUILD_TIMEOUT'] ?? '')
            : (string) ($values['timeout'] * 60);

        return self::steps($server, [
            'DEPLOY_PORT' => $values['port'],
            'DEPLOY_MAX_ARTIFACT' => $values['max_artifact'],
            'DEPLOY_BUILD_TIMEOUT' => $timeout,
            'DEPLOY_OIDC_ISSUERS' => $issuers,
        ]);
    }

    /**
     * Remove an issuer the administrator added.
     *
     * @param  array<string, string>  $server
     * @return list<Step>
     */
    public function planRemoveIssuer(array $server, string $issuer): array
    {
        $rest = array_values(array_filter(self::issuers($server['DEPLOY_OIDC_ISSUERS'] ?? ''), fn (string $item) => $item !== rtrim($issuer, '/')));

        return self::steps($server, ['DEPLOY_OIDC_ISSUERS' => implode(',', $rest)]);
    }

    /**
     * @param  array<string, string>  $server
     * @param  array<string, string>  $wanted
     * @return list<Step>
     */
    private static function steps(array $server, array $wanted): array
    {
        $labels = [
            'DEPLOY_PORT' => __('Change the deploy port'),
            'DEPLOY_MAX_ARTIFACT' => __('Change the largest upload'),
            'DEPLOY_BUILD_TIMEOUT' => __('Change the build time limit'),
            'DEPLOY_OIDC_ISSUERS' => __('Change the CI providers'),
        ];
        $steps = [];
        foreach ($wanted as $key => $value) {
            if ($value !== (string) ($server[$key] ?? '')) {
                $steps[] = new Step($labels[$key], 'v-change-sys-deploy', [$key, $value]);
            }
        }

        return $steps;
    }
}
