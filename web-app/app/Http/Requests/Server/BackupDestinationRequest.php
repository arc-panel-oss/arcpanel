<?php

namespace App\Http\Requests\Server;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\DestinationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add or change a backup destination (E48 spec §4.2, §8.2). The rules follow
 * bd_field_valid and bd_secret_valid of func/backup-driver.sh; the command
 * checks again. Fields come as fields[KEY], secrets as secrets[KEY]; a
 * secret left empty keeps the stored value. BackupPolicy authorizes.
 */
final class BackupDestinationRequest extends FormRequest
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

    /** Only administrators get as far as validation; everyone else gets 403. */
    public function authorize(): bool
    {
        return app(BackupPolicy::class)->manageDestinations(app(Principal::class));
    }

    private ?BackupDestination $stored = null;

    /** The destination being changed, read once after authorization; 404 when there is none. */
    public function stored(): ?BackupDestination
    {
        if ($this->editing() && $this->stored === null) {
            $this->stored = app(DestinationService::class)->find((string) $this->route('destination'));
        }

        return $this->stored;
    }

    /** The driver: picked on a new destination, stored on an existing one. */
    public function driver(): string
    {
        return $this->editing() ? $this->stored()->driver : (string) $this->input('driver');
    }

    private function editing(): bool
    {
        return $this->route('destination') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $driver = $this->driver();
        $rules = [
            'fields' => ['array'],
            'secrets' => ['array'],
            'service_account_file' => ['nullable', 'file', 'max:64'],
        ];
        if (! $this->editing()) {
            $rules['name'] = ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'];
            $rules['driver'] = ['required', 'in:'.implode(',', array_keys(BackupDestination::DRIVERS))];
        }
        $fields = [...BackupDestination::COMMON, ...(BackupDestination::DRIVERS[$driver]['fields'] ?? [])];
        foreach ($fields as $field) {
            $required = in_array($field, self::REQUIRED_FIELDS[$driver] ?? [], true) ? 'required' : 'nullable';
            $rules['fields.'.$field] = [$required, 'string', ...(self::FIELD_RULES[$field] ?? []), ...($field === 'PATH' ? $this->pathRules($driver) : [])];
        }
        foreach (BackupDestination::DRIVERS[$driver]['secrets'] ?? [] as $secret) {
            $rules['secrets.'.$secret] = ['nullable', 'string', 'max:65536', 'not_regex:/\x00/', ...$this->secretRules($secret)];
        }

        return $rules;
    }

    /** @return list<string> */
    private function pathRules(string $driver): array
    {
        if ($driver === 'local') {
            return ['regex:/^\/[A-Za-z0-9._\/-]{1,254}$/', 'not_regex:/(^|\/)\.\.?(\/|$)|\/\//', 'not_regex:/^\/(home|proc|sys|dev|run|etc|usr|bin|sbin|lib|boot|root)(\/|$)/', 'not_in:/'];
        }

        return ['regex:/^[A-Za-z0-9._\/@+~-]{1,255}$/', 'not_regex:/(^|\/)\.\.(\/|$)/'];
    }

    /** @return list<string> */
    private function secretRules(string $secret): array
    {
        return match ($secret) {
            'PASSWORD', 'PASSPHRASE', 'SECRET_ACCESS_KEY', 'APP_KEY' => ['not_regex:/[\r\n]/'],
            'PRIVATE_KEY' => ['regex:/-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
            'TOKEN', 'SERVICE_ACCOUNT' => ['json'],
            'CONFIG' => ['regex:/^\s*type\s*=/m'],
            default => [],
        };
    }

    /** A new destination needs one of its required secrets; a stored one may keep them. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $driver = $this->driver();
            $needed = self::REQUIRED_SECRETS[$driver] ?? [];
            if ($needed === []) {
                return;
            }
            $given = array_keys($this->secrets());
            $stored = $this->stored()?->secrets ?? [];
            if (array_intersect($needed, [...$given, ...$stored]) === []) {
                $validator->errors()->add('secrets.'.$needed[0], __('Enter the credentials of the destination.'));
            }
        }];
    }

    /**
     * The plain fields of the driver and the common ones; an unchecked
     * switch gives "no".
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        $driver = $this->driver();
        $input = (array) $this->input('fields', []);
        $values = [];
        foreach ([...BackupDestination::COMMON, ...(BackupDestination::DRIVERS[$driver]['fields'] ?? [])] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if (in_array($field, ['ENCRYPT', 'TLS_VERIFY', 'PATH_STYLE'], true)) {
                $value = $value === 'yes' ? 'yes' : 'no';
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * The secrets given; empty ones keep the stored value. A service
     * account file wins over the pasted text.
     *
     * @return array<string, string>
     */
    public function secrets(): array
    {
        $driver = $this->driver();
        $input = (array) $this->input('secrets', []);
        $values = [];
        foreach (BackupDestination::DRIVERS[$driver]['secrets'] ?? [] as $secret) {
            $value = (string) ($input[$secret] ?? '');
            if ($secret === 'SERVICE_ACCOUNT' && $this->hasFile('service_account_file')) {
                $value = (string) file_get_contents($this->file('service_account_file')->getRealPath());
            }
            if (trim($value) !== '') {
                $values[$secret] = in_array($secret, ['PRIVATE_KEY', 'TOKEN', 'SERVICE_ACCOUNT', 'CONFIG'], true)
                    ? rtrim(str_replace("\r\n", "\n", $value))."\n" : $value;
            }
        }

        return $values;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $names = [];
        foreach (BackupDestination::fieldLabels() as $key => $label) {
            $names['fields.'.$key] = $label;
            $names['secrets.'.$key] = $label;
        }

        return $names;
    }
}
