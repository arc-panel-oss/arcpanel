<?php

namespace App\Services\Backups;

/**
 * One backup destination as `v-list-backup-destination(s) json` returns it
 * (E48 spec §4): its fields, its state and the names of its stored secrets,
 * never their values.
 */
final class BackupDestination
{
    /** Drivers and their fields, in the order of the form (spec §4.2). */
    public const DRIVERS = [
        'local' => ['fields' => ['PATH'], 'secrets' => [], 'remote' => false],
        'sftp' => ['fields' => ['HOST', 'PORT', 'USERNAME', 'PATH'], 'secrets' => ['PASSWORD', 'PRIVATE_KEY', 'PASSPHRASE'], 'remote' => true],
        'ftp' => ['fields' => ['HOST', 'PORT', 'USERNAME', 'TLS', 'TLS_VERIFY', 'PATH'], 'secrets' => ['PASSWORD'], 'remote' => true],
        's3' => ['fields' => ['PROVIDER', 'ENDPOINT', 'REGION', 'BUCKET', 'ACCESS_KEY_ID', 'PATH_STYLE', 'PATH'], 'secrets' => ['SECRET_ACCESS_KEY'], 'remote' => true],
        'b2' => ['fields' => ['BUCKET', 'KEY_ID', 'PATH'], 'secrets' => ['APP_KEY'], 'remote' => true],
        'gdrive' => ['fields' => ['AUTH', 'ROOT_FOLDER_ID', 'SHARED_DRIVE_ID', 'PATH'], 'secrets' => ['TOKEN', 'SERVICE_ACCOUNT'], 'remote' => true],
        'dropbox' => ['fields' => ['PATH'], 'secrets' => ['TOKEN'], 'remote' => true],
        'onedrive' => ['fields' => ['DRIVE_ID', 'DRIVE_TYPE', 'PATH'], 'secrets' => ['TOKEN'], 'remote' => true],
        'webdav' => ['fields' => ['URL', 'VENDOR', 'USERNAME', 'PATH'], 'secrets' => ['PASSWORD'], 'remote' => true],
        'rclone' => ['fields' => ['REMOTE', 'PATH'], 'secrets' => ['CONFIG'], 'remote' => true],
    ];

    /** Fields every driver has. */
    public const COMMON = ['DESCRIPTION', 'ENCRYPT', 'BWLIMIT'];

    /**
     * @param  array<string, string>  $fields  the driver's fields
     * @param  list<string>  $secrets  names of the stored secrets
     * @param  list<string>  $jobs  IDs of the jobs that target it
     */
    public function __construct(
        public readonly string $name,
        public readonly string $driver,
        public readonly string $description,
        public readonly bool $encrypt,
        public readonly string $bwlimit,
        public readonly array $fields,
        public readonly array $secrets,
        public readonly string $state,
        public readonly string $checked,
        public readonly string $hostKey,
        public readonly string $kit,
        public readonly array $jobs,
        public readonly string $created,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $name, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));
        $driver = $value('DRIVER');
        $fields = [];
        foreach (self::DRIVERS[$driver]['fields'] ?? [] as $field) {
            $fields[$field] = $value($field);
        }
        $list = fn (string $key): array => array_values(array_filter(preg_split('/[\s,]+/', $value($key)) ?: [], fn ($v) => $v !== ''));

        return new self(
            name: $name,
            driver: $driver,
            description: $value('DESCRIPTION'),
            encrypt: $value('ENCRYPT') === 'yes',
            bwlimit: $value('BWLIMIT'),
            fields: $fields,
            secrets: $list('SECRETS'),
            state: $value('STATE') ?: 'unchecked',
            checked: $value('CHECKED'),
            hostKey: $value('HOST_KEY'),
            kit: $value('KIT'),
            jobs: $list('JOBS'),
            created: $value('CREATED'),
        );
    }

    public function isRemote(): bool
    {
        return self::DRIVERS[$this->driver]['remote'] ?? true;
    }

    /** Where the archives go, for lists: a folder, a bucket, a host. */
    public function location(): string
    {
        $path = $this->fields['PATH'] ?? '';

        return match ($this->driver) {
            'local' => $path,
            'sftp', 'ftp' => trim(($this->fields['USERNAME'] ?? '').'@'.($this->fields['HOST'] ?? ''), '@').($path !== '' ? ':'.$path : ''),
            's3', 'b2' => ($this->fields['BUCKET'] ?? '').($path !== '' ? '/'.ltrim($path, '/') : ''),
            'webdav' => ($this->fields['URL'] ?? '').($path !== '' ? ' /'.ltrim($path, '/') : ''),
            'rclone' => ($this->fields['REMOTE'] ?? '').':'.$path,
            default => $path !== '' ? '/'.ltrim($path, '/') : '/',
        };
    }

    /** An encrypted destination whose recovery kit is older than its key, or never exported. */
    public function needsKit(): bool
    {
        return $this->encrypt && $this->kit === '';
    }

    /** Label of a driver for pages. */
    public static function driverLabel(string $driver): string
    {
        return match ($driver) {
            'local' => __('Local folder'),
            'sftp' => 'SFTP',
            'ftp' => 'FTP',
            's3' => __('S3-compatible'),
            'b2' => 'Backblaze B2',
            'gdrive' => 'Google Drive',
            'dropbox' => 'Dropbox',
            'onedrive' => 'OneDrive',
            'webdav' => 'WebDAV',
            'rclone' => __('Other (rclone)'),
            default => $driver,
        };
    }

    /** @return array<string, string> labels of every field and secret */
    public static function fieldLabels(): array
    {
        return [
            'DESCRIPTION' => __('Description'), 'ENCRYPT' => __('Encrypt archives'), 'BWLIMIT' => __('Bandwidth limit'),
            'PATH' => __('Folder'), 'HOST' => __('Host'), 'PORT' => __('Port'), 'USERNAME' => __('Username'),
            'TLS' => __('TLS'), 'TLS_VERIFY' => __('Verify the certificate'), 'PROVIDER' => __('Provider'),
            'ENDPOINT' => __('Endpoint'), 'REGION' => __('Region'), 'BUCKET' => __('Bucket'), 'ACCESS_KEY_ID' => __('Access key ID'),
            'PATH_STYLE' => __('Path-style URLs'), 'KEY_ID' => __('Application key ID'), 'AUTH' => __('Sign-in'),
            'ROOT_FOLDER_ID' => __('Root folder ID'), 'SHARED_DRIVE_ID' => __('Shared drive ID'), 'DRIVE_ID' => __('Drive ID'),
            'DRIVE_TYPE' => __('Drive type'), 'URL' => __('URL'), 'VENDOR' => __('Server'), 'REMOTE' => __('Remote'),
            'PASSWORD' => __('Password'), 'PRIVATE_KEY' => __('Private key'), 'PASSPHRASE' => __('Key passphrase'),
            'SECRET_ACCESS_KEY' => __('Secret access key'), 'APP_KEY' => __('Application key'), 'TOKEN' => __('Token'),
            'SERVICE_ACCOUNT' => __('Service account'), 'CONFIG' => __('rclone configuration'),
        ];
    }

    /** The choices of the fields that have a fixed list. */
    public static function fieldOptions(string $field): ?array
    {
        return match ($field) {
            'TLS' => ['explicit' => __('Explicit (FTPES)'), 'implicit' => __('Implicit (FTPS)'), 'none' => __('None')],
            'PROVIDER' => ['AWS' => 'Amazon S3', 'Cloudflare' => 'Cloudflare R2', 'Wasabi' => 'Wasabi', 'Other' => __('Other S3-compatible')],
            'AUTH' => ['token' => __('Account token'), 'service-account' => __('Service account')],
            'DRIVE_TYPE' => ['personal' => __('Personal'), 'business' => __('Business'), 'documentLibrary' => __('SharePoint library')],
            'VENDOR' => ['nextcloud' => 'Nextcloud', 'owncloud' => 'ownCloud', 'other' => __('Other')],
            default => null,
        };
    }

    /** The rclone backend of the drivers whose token comes from `rclone authorize`. */
    public static function authorizeBackend(string $driver): ?string
    {
        return match ($driver) {
            'gdrive' => 'drive',
            'dropbox' => 'dropbox',
            'onedrive' => 'onedrive',
            default => null,
        };
    }

    /** Icon of a driver. */
    public static function driverIcon(string $driver): string
    {
        return match ($driver) {
            'local' => 'disk',
            'sftp', 'ftp' => 'server',
            's3', 'b2' => 'archive',
            'gdrive', 'dropbox', 'onedrive' => 'upload',
            default => 'globe',
        };
    }
}
