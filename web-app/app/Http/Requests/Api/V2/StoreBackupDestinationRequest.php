<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\DestinationRules;
use Illuminate\Validation\Validator;

/**
 * POST /system/backup-destinations (E48 spec §9): the fields of the driver
 * in `fields`, its secrets in `secrets` (write-only).
 */
final class StoreBackupDestinationRequest extends ApiRequest
{
    /** Administrators only: everyone else gets 403 before validation. */
    public function authorize(): bool
    {
        return app(BackupPolicy::class)->manageDestinations(app(Principal::class));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Lower-case letters, digits and hyphens, at most 32. */
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
            'driver' => ['required', 'string', 'in:'.implode(',', array_keys(BackupDestination::DRIVERS))],
            /** The fields of the driver by name, for example `{"HOST": "backup.example.com", "PORT": "22", "PATH": "server1"}`, and `DESCRIPTION`, `ENCRYPT` (`yes` or `no`) and `BWLIMIT`. */
            'fields' => ['array'],
            /** The secrets of the driver by name, for example `{"PASSWORD": "…"}`; never returned. */
            'secrets' => ['array'],
            ...DestinationRules::rules($this->driver()),
        ];
    }

    /** @return list<string> */
    protected function multiLineFields(): array
    {
        return array_map(fn (string $secret) => 'secrets.'.$secret, DestinationRules::MULTI_LINE);
    }

    /** One of the required secrets of the driver is given. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $missing = DestinationRules::missingSecret($this->driver(), array_keys($this->secrets()), []);
            if ($missing !== null) {
                $validator->errors()->add('secrets.'.$missing, __('Enter the credentials of the destination.'));
            }
        }];
    }

    public function driver(): string
    {
        return (string) $this->input('driver');
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return DestinationRules::fields($this->driver(), (array) $this->input('fields', []));
    }

    /** @return array<string, string> */
    public function secrets(): array
    {
        return DestinationRules::secrets($this->driver(), (array) $this->input('secrets', []));
    }
}
