<?php

namespace App\Services\Server;

use Illuminate\Validation\ValidationException;

/**
 * The field checks of the server settings tabs (E34 batch 6 spec D66): the
 * rules that need the server's state, shared by the settings page and API
 * v2. Each check throws a ValidationException on the page's field names,
 * which API v2 uses too.
 */
final class ServerSettingsActions
{
    public function __construct(private readonly ServerSettings $settings) {}

    /**
     * General: the time zone, language and theme must be among the server's
     * choices. A null value was not given and is not checked.
     *
     * @param  array{timezone?: ?string, language?: ?string, theme?: ?string}  $values
     */
    public function checkGeneral(array $values): void
    {
        $choices = [
            'timezone' => [fn () => $this->settings->timezones(), __('Please select a valid time zone.')],
            'language' => [fn () => $this->settings->languages(), __('Please select a valid language.')],
            'theme' => [fn () => $this->settings->themes(), __('Please select a valid theme.')],
        ];
        $errors = [];
        foreach ($choices as $field => [$list, $message]) {
            if (($values[$field] ?? null) !== null && ! in_array($values[$field], $list(), true)) {
                $errors[$field] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Web: the PHP versions that may be the default (the installed ones).
     *
     * @return list<string>
     */
    public function installedPhp(): array
    {
        return array_values(array_map('strval', array_keys(array_filter($this->settings->phpVersions(), fn (array $v) => $v['installed']))));
    }

    /**
     * Mail: the relay password is required whenever the relay is written
     * again (turned on, or its host, port or user changed).
     *
     * @param  array{relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: ?string}  $values
     */
    public function checkMail(array $values): void
    {
        if ($values['relay_password'] === null
            && $this->settings->relayNeedsPassword($values['relay'], $values['relay_host'], $values['relay_port'], $values['relay_user'])) {
            throw ValidationException::withMessages(['relay_password' => sprintf(__('Field "%s" can not be blank.'), __('Password'))]);
        }
    }

    /** Databases: a password is changed only for a MySQL host the server lists. */
    public function knowsDatabaseHost(string $type, string $host): bool
    {
        if ($type !== 'mysql') {
            return false;
        }
        foreach ($this->settings->databaseHosts() as $known) {
            if ($known['type'] === $type && $known['host'] === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backups: the fields of the remote type, and its password or key when
     * the remote is written again (not for rclone).
     *
     * @param  array<string, mixed>  $values  the backup values of PATCH /api/v2/system/settings (ServerSettingsApi)
     */
    public function checkBackups(array $values): void
    {
        if (! $values['remote']) {
            return;
        }
        $missing = match ($values['remote_type']) {
            'b2' => array_filter(['bucket' => __('Bucket'), 'key_id' => __('Application Key ID')], fn ($label, $field) => $values[$field] === '', ARRAY_FILTER_USE_BOTH),
            'rclone' => array_filter(['host' => __('Host')], fn ($label, $field) => $values[$field] === '', ARRAY_FILTER_USE_BOTH),
            default => array_filter(['host' => __('Host'), 'username' => __('Username')], fn ($label, $field) => $values[$field] === '', ARRAY_FILTER_USE_BOTH),
        };
        if ($values['remote_type'] !== 'rclone' && $values['remote_secret'] === null
            && $this->settings->remoteChanged($this->settings->remoteBackupType($this->settings->config()), $values)) {
            $missing['password'] = $values['remote_type'] === 'b2' ? __('Application Key') : __('Password');
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(array_map(fn ($label) => sprintf(__('Field "%s" can not be blank.'), $label), $missing));
        }
    }

    /** Plugins: the plugins the system task installs and removes. */
    public static function knowsPlugin(string $plugin): bool
    {
        return isset(ServerSettings::PLUGINS[$plugin]);
    }
}
