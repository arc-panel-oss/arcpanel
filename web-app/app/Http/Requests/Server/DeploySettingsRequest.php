<?php

namespace App\Http\Requests\Server;

use App\Services\Server\DeploySettings;
use Closure;

/**
 * The Git Deploy card of the Web tab (E20 spec §3.4), with the limits of
 * v-change-sys-deploy: a port of 1024-65535 or empty to turn the endpoint
 * off, an upload limit of 1M-1024M, a build time limit of 1-120 minutes
 * (60-7200 seconds) and an optional https:// issuer to add. ServerPolicy
 * authorizes.
 */
final class DeploySettingsRequest extends ServerSettingsRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'port' => trim((string) $this->input('port')),
            'max_artifact' => strtoupper(trim((string) $this->input('max_artifact'))),
            'new_issuer' => rtrim(trim((string) $this->input('new_issuer')), '/'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'port' => ['nullable', 'integer', 'between:1024,65535'],
            'max_artifact' => ['required', 'string', 'regex:/^\d{1,4}[MG]$/', function (string $attribute, mixed $value, Closure $fail) {
                $mb = (int) $value * (str_ends_with((string) $value, 'G') ? 1024 : 1);
                if ($mb < 1 || $mb > 1024) {
                    $fail(__('The largest upload must be between 1M and 1024M.'));
                }
            }],
            'timeout' => ['required', 'integer', 'between:1,120'],
            'new_issuer' => ['nullable', 'string', 'max:255', 'regex:'.DeploySettings::ISSUER_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'max_artifact.regex' => __('Enter a size such as 512M or 1G.'),
            'new_issuer.regex' => __('Enter the https:// URL of the issuer, without a query.'),
        ];
    }

    /** @return array{port: string, max_artifact: string, timeout: int, new_issuer: ?string} */
    public function values(): array
    {
        return [
            'port' => $this->filled('port') ? (string) (int) $this->input('port') : '',
            'max_artifact' => (string) $this->input('max_artifact'),
            'timeout' => (int) $this->input('timeout'),
            'new_issuer' => $this->filled('new_issuer') ? (string) $this->input('new_issuer') : null,
        ];
    }
}
