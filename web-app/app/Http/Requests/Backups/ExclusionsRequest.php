<?php

namespace App\Http\Requests\Backups;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Backup exclusions: one entry per line in the syntax that
 * v-update-user-backup-exclusions accepts (checked again by the command).
 */
final class ExclusionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // domain or *, then optional :folder or :account parts
            'web' => ['nullable', 'string', 'max:20000', $this->lines('/^(\*|[\pL\pN][\pL\pN.-]*)(:[^:\s,\'"]+)*$/u')],
            'mail' => ['nullable', 'string', 'max:20000', $this->lines('/^(\*|[\pL\pN][\pL\pN.-]*)(:[^:\s,\'"]+)*$/u')],
            'db' => ['nullable', 'string', 'max:20000', $this->lines('/^(\*|[\w-]+)$/')],
            // paths below the home directory, as func/main.sh checks them
            'userdir' => ['nullable', 'string', 'max:20000', $this->lines('/^[a-zA-Z0-9_.][a-zA-Z0-9_.\/-]*$/', true)],
            'toggles' => ['nullable', 'array'],
            'toggles.*' => ['string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'web' => __('Web Domains'),
            'mail' => __('Mail Domains'),
            'db' => __('Databases'),
            'userdir' => __('User Directories'),
        ];
    }

    /** Every non-empty line must match $pattern (and hold no ".." when $paths). */
    private function lines(string $pattern, bool $paths = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern, $paths): void {
            foreach (preg_split('/\R/', (string) $value) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match($pattern, $line) !== 1 || ($paths && str_contains($line, '..'))) {
                    $fail(sprintf(__('Invalid entry: %s'), $line));

                    return;
                }
            }
        };
    }
}
