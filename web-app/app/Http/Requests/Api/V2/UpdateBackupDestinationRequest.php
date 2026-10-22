<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\DestinationRules;
use App\Services\Backups\DestinationService;

/**
 * PATCH /system/backup-destinations/{destination} (E48 spec §9): fields
 * left out keep their value, secrets left out stay stored. Administrators
 * only (403 before validation), an unknown destination 404.
 */
final class UpdateBackupDestinationRequest extends ApiRequest
{
    private ?BackupDestination $stored = null;

    public function authorize(): bool
    {
        return app(BackupPolicy::class)->manageDestinations(app(Principal::class));
    }

    /** The destination being changed; 404 when there is none. */
    public function stored(): BackupDestination
    {
        return $this->stored ??= app(DestinationService::class)->find((string) $this->route('destination'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Without a route (the OpenAPI document) the common fields only
        $rules = DestinationRules::rules($this->route('destination') !== null ? $this->stored()->driver : '');
        // A field left out keeps its value, so none is required here; a required one may not be emptied
        foreach ($rules as $key => $list) {
            if (str_starts_with($key, 'fields.') && in_array('required', $list, true)) {
                $rules[$key] = ['sometimes', 'filled', ...array_values(array_diff($list, ['required']))];
            }
        }

        return [
            /** Only the fields that change; `DESCRIPTION`, `ENCRYPT` and `BWLIMIT` included. */
            'fields' => ['array'],
            /** Only the secrets that change; never returned. */
            'secrets' => ['array'],
            ...$rules,
        ];
    }

    /** @return list<string> */
    protected function multiLineFields(): array
    {
        return array_map(fn (string $secret) => 'secrets.'.$secret, DestinationRules::MULTI_LINE);
    }

    /**
     * The stored fields with those the request changes.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return DestinationRules::fields($this->stored()->driver, [...DestinationRules::storedFields($this->stored()), ...(array) $this->input('fields', [])]);
    }

    /** @return array<string, string> only the secrets that change */
    public function secrets(): array
    {
        return DestinationRules::secrets($this->stored()->driver, (array) $this->input('secrets', []));
    }
}
