<?php

namespace App\Http\Requests\Server;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\DestinationRules;
use App\Services\Backups\DestinationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add or change a backup destination (E48 spec §4.2, §8.2). The rules are
 * those of DestinationRules; the command checks again. Fields come as
 * fields[KEY], secrets as secrets[KEY]; a secret left empty keeps the
 * stored value. BackupPolicy authorizes.
 */
final class BackupDestinationRequest extends FormRequest
{
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
        $rules = [
            'fields' => ['array'],
            'secrets' => ['array'],
            'service_account_file' => ['nullable', 'file', 'max:64'],
        ];
        if (! $this->editing()) {
            $rules['name'] = ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'];
            $rules['driver'] = ['required', 'in:'.implode(',', array_keys(BackupDestination::DRIVERS))];
        }

        return [...$rules, ...DestinationRules::rules($this->driver())];
    }

    /** A new destination needs one of its required secrets; a stored one may keep them. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $missing = DestinationRules::missingSecret($this->driver(), array_keys($this->secrets()), $this->stored()?->secrets ?? []);
            if ($missing !== null) {
                $validator->errors()->add('secrets.'.$missing, __('Enter the credentials of the destination.'));
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
        return DestinationRules::fields($this->driver(), (array) $this->input('fields', []));
    }

    /**
     * The secrets given; empty ones keep the stored value. A service
     * account file wins over the pasted text.
     *
     * @return array<string, string>
     */
    public function secrets(): array
    {
        $input = (array) $this->input('secrets', []);
        if ($this->hasFile('service_account_file')) {
            $input['SERVICE_ACCOUNT'] = (string) file_get_contents($this->file('service_account_file')->getRealPath());
        }

        return DestinationRules::secrets($this->driver(), $input);
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
