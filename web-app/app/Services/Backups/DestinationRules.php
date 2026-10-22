<?php

namespace App\Services\Backups;

/**
 * The checks of a backup destination's fields and secrets (E48 spec §4.2),
 * as bd_field_valid and bd_secret_valid of func/backup-driver.sh make them,
 * for the panel's form and REST API v2. The commands check again.
 */
final class DestinationRules
{
    /** Rules of each field, as bd_field_valid checks it. */
    private const FIELD_RULES = [
        'DESCRIPTION' => ['max:128', 'not_regex:/["\'\\\\`$\x00-\x1f]/'],
        'ENCRYPT' => ['in:yes,no'],
        'TLS_VERIFY' => ['in:yes,no'],
        'PATH_STYLE' => ['in:yes,no'],
        'BWLIMIT' => ['regex:/^\d{1,6}[KMG]?$/'],
        'HOST' => ['regex:/^[A-Za-z0-9][A-Za-z0-9.:-]{0,252}$/'],
        'PORT' => ['integer', 'between:1,65535'],
        'USERNAME' => ['regex:/^[A-Za-z0-9._@+-]{1,128}$/'],
        'TLS' => ['in:explicit,implicit,none'],
        'PROVIDER' => ['in:AWS,Cloudflare,Wasabi,Other'],
        'ENDPOINT' => ['regex:/^(https:\/\/[A-Za-z0-9.-]+|http:\/\/(127\.0\.0\.1|localhost))(:\d{1,5})?\/?$/'],
        'REGION' => ['regex:/^[a-z0-9-]{1,32}$/'],
        'ACCESS_KEY_ID' => ['regex:/^[A-Za-z0-9+\/=._-]{3,128}$/'],
        'KEY_ID' => ['regex:/^[A-Za-z0-9+\/=._-]{3,128}$/'],
        'AUTH' => ['in:token,service-account'],
        'ROOT_FOLDER_ID' => ['regex:/^[A-Za-z0-9!_.-]{1,128}$/'],
        'SHARED_DRIVE_ID' => ['regex:/^[A-Za-z0-9!_.-]{1,128}$/'],
        'DRIVE_ID' => ['regex:/^[A-Za-z0-9!_.-]{1,128}$/'],
        'DRIVE_TYPE' => ['in:personal,business,documentLibrary'],
        'URL' => ['regex:/^https?:\/\/[A-Za-z0-9.-]+(:\d{1,5})?(\/[A-Za-z0-9._~\/%-]*)?$/'],
        'VENDOR' => ['in:nextcloud,owncloud,other'],
        'REMOTE' => ['regex:/^[A-Za-z0-9_.-]{1,64}$/'],
    ];

    /** Fields a destination cannot do without (bd_required_fields). */
    private const REQUIRED_FIELDS = [
        'local' => ['PATH'], 'ftp' => ['HOST', 'USERNAME'], 'sftp' => ['HOST', 'USERNAME'], 's3' => ['BUCKET', 'ACCESS_KEY_ID'],
        'b2' => ['BUCKET', 'KEY_ID'], 'gdrive' => ['AUTH'], 'webdav' => ['URL'], 'rclone' => ['REMOTE'],
    ];

    /** Secrets of which one must be stored (bd_required_secrets). */
    private const REQUIRED_SECRETS = [
        'ftp' => ['PASSWORD'], 'webdav' => ['PASSWORD'], 'sftp' => ['PASSWORD', 'PRIVATE_KEY'], 's3' => ['SECRET_ACCESS_KEY'],
        'b2' => ['APP_KEY'], 'gdrive' => ['TOKEN', 'SERVICE_ACCOUNT'], 'dropbox' => ['TOKEN'], 'onedrive' => ['TOKEN'], 'rclone' => ['CONFIG'],
    ];

    /** Switches: anything but "yes" is "no". */
    private const SWITCHES = ['ENCRYPT', 'TLS_VERIFY', 'PATH_STYLE'];

    /** Secrets that span lines: they end with one line break. */
    public const MULTI_LINE = ['PRIVATE_KEY', 'TOKEN', 'SERVICE_ACCOUNT', 'CONFIG'];

    /** @return list<string> the common fields and those of DRIVER, in the order of the form */
    public static function fieldNames(string $driver): array
    {
        return [...BackupDestination::COMMON, ...(BackupDestination::DRIVERS[$driver]['fields'] ?? [])];
    }

    /** @return list<string> */
    public static function secretNames(string $driver): array
    {
        return BackupDestination::DRIVERS[$driver]['secrets'] ?? [];
    }

    /**
     * Rules of fields.KEY and secrets.KEY of DRIVER.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $driver): array
    {
        $rules = [];
        foreach (self::fieldNames($driver) as $field) {
            $required = in_array($field, self::REQUIRED_FIELDS[$driver] ?? [], true) ? 'required' : 'nullable';
            $rules['fields.'.$field] = [$required, 'string', ...(self::FIELD_RULES[$field] ?? []), ...($field === 'PATH' ? self::pathRules($driver) : [])];
        }
        foreach (self::secretNames($driver) as $secret) {
            $rules['secrets.'.$secret] = ['nullable', 'string', 'max:65536', 'not_regex:/\x00/', ...self::secretRules($secret)];
        }

        return $rules;
    }

    /**
     * The secret to report when none of the required secrets of DRIVER is
     * given or stored; null when the destination has what it needs.
     *
     * @param  list<string>  $given
     * @param  list<string>  $stored
     */
    public static function missingSecret(string $driver, array $given, array $stored): ?string
    {
        $needed = self::REQUIRED_SECRETS[$driver] ?? [];

        return $needed !== [] && array_intersect($needed, [...$given, ...$stored]) === [] ? $needed[0] : null;
    }

    /**
     * The fields of DRIVER from INPUT, trimmed; a switch is "yes" or "no".
     *
     * @param  array<mixed>  $input
     * @return array<string, string>
     */
    public static function fields(string $driver, array $input): array
    {
        $values = [];
        foreach (self::fieldNames($driver) as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            $values[$field] = in_array($field, self::SWITCHES, true) ? ($value === 'yes' ? 'yes' : 'no') : $value;
        }

        return $values;
    }

    /**
     * The secrets of DRIVER that INPUT gives; empty ones keep the stored
     * value.
     *
     * @param  array<mixed>  $input
     * @return array<string, string>
     */
    public static function secrets(string $driver, array $input): array
    {
        $values = [];
        foreach (self::secretNames($driver) as $secret) {
            $value = (string) ($input[$secret] ?? '');
            if (trim($value) !== '') {
                $values[$secret] = in_array($secret, self::MULTI_LINE, true) ? rtrim(str_replace("\r\n", "\n", $value))."\n" : $value;
            }
        }

        return $values;
    }

    /**
     * The stored fields of DESTINATION, as fields() gives them.
     *
     * @return array<string, string>
     */
    public static function storedFields(BackupDestination $destination): array
    {
        return self::fields($destination->driver, [
            ...$destination->fields,
            'DESCRIPTION' => $destination->description,
            'ENCRYPT' => $destination->encrypt ? 'yes' : 'no',
            'BWLIMIT' => $destination->bwlimit,
        ]);
    }

    /** @return list<string> */
    private static function pathRules(string $driver): array
    {
        if ($driver === 'local') {
            return ['regex:/^\/[A-Za-z0-9._\/-]{1,254}$/', 'not_regex:/(^|\/)\.\.?(\/|$)|\/\//', 'not_regex:/^\/(home|proc|sys|dev|run|etc|usr|bin|sbin|lib|boot|root)(\/|$)/', 'not_in:/'];
        }

        return ['regex:/^[A-Za-z0-9._\/@+~-]{1,255}$/', 'not_regex:/(^|\/)\.\.(\/|$)/'];
    }

    /** @return list<string> */
    private static function secretRules(string $secret): array
    {
        return match ($secret) {
            'PASSWORD', 'PASSPHRASE', 'SECRET_ACCESS_KEY', 'APP_KEY' => ['not_regex:/[\r\n]/'],
            'PRIVATE_KEY' => ['regex:/-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
            'TOKEN', 'SERVICE_ACCOUNT' => ['json'],
            'CONFIG' => ['regex:/^\s*type\s*=/m'],
            default => [],
        };
    }
}
