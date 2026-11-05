<?php

namespace App\Services\Alerts;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * Alert rules, channels and open alerts of a scope (E16,
 * docs/features/specs/2026-11-05-002-e16-alerts-design.md §6) through the
 * v-*-alert-* commands. A scope is `system` (the server) or a user. Channel
 * secrets only travel into the commands, in a Secret file; no command the
 * panel runs prints one.
 */
final class AlertStore
{
    public const CHANNEL_TYPES = ['email', 'telegram', 'slack', 'discord', 'mattermost'];

    public const SECRET_TYPES = ['telegram', 'slack', 'discord', 'mattermost'];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * `v-list-alert-rules SCOPE json`: rule => row (RULE, KIND, TITLE,
     * ENABLED, WARNING, CRITICAL, FOR, SEVERITY, COOLDOWN, CHANNELS, OPTIONS,
     * UNIT, LEVELS, INTERVAL, SCOPES, CUSTOM).
     *
     * @return array<string, array<string, string>>
     */
    public function rules(string $scope): array
    {
        return self::rows($this->arc->json('v-list-alert-rules', [$scope]));
    }

    /**
     * `v-list-alert-channels SCOPE json`: ID => row (ID, NAME, TYPE, TARGET,
     * ENABLED, STATUS, LAST_SENT, FAILS, SECRET_SET).
     *
     * @return array<string, array<string, string>>
     */
    public function channels(string $scope): array
    {
        return self::rows($this->arc->json('v-list-alert-channels', [$scope]));
    }

    /**
     * `v-list-alerts SCOPE json`: the open alerts, ID => row.
     *
     * @return array<string, array<string, string>>
     */
    public function open(string $scope): array
    {
        return self::rows($this->arc->json('v-list-alerts', [$scope]));
    }

    /**
     * `v-list-alert-history SCOPE SINCE json`: history lines, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $scope, ?string $since): array
    {
        $result = $this->arc->run('v-list-alert-history', [$scope, $since ?? '', 'json']);
        $data = json_decode($result->stdout, true);

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * Change a rule: the ten arguments of v-change-alert-rule.
     *
     * @param  array{enabled: bool, warning?: ?string, critical?: ?string, for?: ?string, severity?: ?string, cooldown?: ?string, channels?: list<string>, options?: ?string}  $rule
     */
    public function changeRule(string $scope, string $name, array $rule): CommandResult
    {
        return $this->arc->run('v-change-alert-rule', self::ruleArgs($scope, $name, $rule), Options::allowFailure());
    }

    /**
     * @param  array{enabled: bool, warning?: ?string, critical?: ?string, for?: ?string, severity?: ?string, cooldown?: ?string, channels?: list<string>, options?: ?string}  $rule
     * @return list<string>
     */
    public static function ruleArgs(string $scope, string $name, array $rule): array
    {
        return [
            $scope, $name, $rule['enabled'] ? 'yes' : 'no',
            (string) ($rule['warning'] ?? ''), (string) ($rule['critical'] ?? ''), (string) ($rule['for'] ?? '0'),
            (string) ($rule['severity'] ?? ''), (string) ($rule['cooldown'] ?? '1440'),
            implode(',', $rule['channels'] ?? []), (string) ($rule['options'] ?? ''),
        ];
    }

    /** Add a channel; the ID it prints is in the result's stdout. */
    public function addChannel(string $scope, string $name, string $type, string $target, ?string $secret): CommandResult
    {
        return $this->arc->run('v-add-alert-channel', self::addArgs($scope, $name, $type, $target, $secret), Options::allowFailure());
    }

    /** @return list<string|Secret> */
    public static function addArgs(string $scope, string $name, string $type, string $target, ?string $secret): array
    {
        $args = [$scope, $name, $type, $target];
        if ($secret !== null && $secret !== '') {
            $args[] = Secret::file($secret);
        }

        return $args;
    }

    /** Change a channel; an empty secret keeps the stored one. */
    public function changeChannel(string $scope, string $id, string $name, bool $enabled, ?string $target, ?string $secret): CommandResult
    {
        return $this->arc->run('v-change-alert-channel', self::changeArgs($scope, $id, $name, $enabled, $target, $secret), Options::allowFailure());
    }

    /** @return list<string|Secret> */
    public static function changeArgs(string $scope, string $id, string $name, bool $enabled, ?string $target, ?string $secret): array
    {
        $args = [$scope, $id, $name, $enabled ? 'yes' : 'no'];
        if ($target !== null || ($secret !== null && $secret !== '')) {
            $args[] = (string) $target;
        }
        if ($secret !== null && $secret !== '') {
            $args[] = Secret::file($secret);
        }

        return $args;
    }

    public function deleteChannel(string $scope, string $id): CommandResult
    {
        return $this->arc->run('v-delete-alert-channel', [$scope, $id], Options::allowFailure());
    }

    /**
     * Send a test message now: ['ok' => bool, 'error' => string].
     *
     * @return array{ok: bool, error: string}
     */
    public function testChannel(string $scope, string $id): array
    {
        $result = $this->arc->run('v-test-alert-channel', [$scope, $id, 'json'], Options::allowFailure());
        $data = json_decode($result->stdout, true);
        if (is_array($data) && isset($data['RESULT'])) {
            return ['ok' => $data['RESULT'] === 'ok', 'error' => (string) ($data['ERROR'] ?? '')];
        }

        return ['ok' => false, 'error' => self::reason($result)];
    }

    /** The reason a command gave, without its `Error:` prefix. */
    public static function reason(CommandResult $result): string
    {
        return (string) preg_replace('/^Error:\s*/', '', $result->errorMessage());
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, array<string, string>>
     */
    private static function rows(array $data): array
    {
        $rows = [];
        foreach ($data as $key => $row) {
            if (is_array($row)) {
                $rows[(string) $key] = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $row);
            }
        }

        return $rows;
    }
}
