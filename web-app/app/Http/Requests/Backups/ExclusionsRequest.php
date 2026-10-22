<?php

namespace App\Http\Requests\Backups;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Backup exclusions as structured lists (E48 spec §8.1): web domains, mail
 * domains and databases picked from the account's records with optional
 * folders or accounts, or all of a kind; the cron jobs switch; user folders
 * as free entries. v-update-user-backup-exclusions checks again.
 */
final class ExclusionsRequest extends FormRequest
{
    private const NAME = '/^[\pL\pN][\pL\pN._-]*$/u';

    private const PART = '/^[^:\s,\'"]+$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'web_all' => ['nullable', 'boolean'],
            'web' => ['nullable', 'array'],
            'web.*' => ['string', 'max:253', 'regex:'.self::NAME],
            'web_sub' => ['nullable', 'array'],
            'web_sub.*' => ['nullable', 'string', 'max:2000', $this->parts()],
            'mail_all' => ['nullable', 'boolean'],
            'mail' => ['nullable', 'array'],
            'mail.*' => ['string', 'max:253', 'regex:'.self::NAME],
            'mail_sub' => ['nullable', 'array'],
            'mail_sub.*' => ['nullable', 'string', 'max:2000', $this->parts()],
            'db_all' => ['nullable', 'boolean'],
            'db' => ['nullable', 'array'],
            'db.*' => ['string', 'max:64', 'regex:/^[\w-]+$/'],
            'cron' => ['nullable', 'boolean'],
            // paths below the home directory, as func/main.sh checks them
            'userdir' => ['nullable', 'string', 'max:20000', $this->lines('/^[a-zA-Z0-9_.][a-zA-Z0-9_.\/-]*$/')],
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

    /**
     * The entries of KEY (WEB, MAIL, DB): ["*"] for all, else each ticked
     * name with its folders or accounts, "name:part:part".
     *
     * @return list<string>
     */
    public function entries(string $key): array
    {
        $field = strtolower($key);
        if ($this->boolean($field.'_all')) {
            return ['*'];
        }
        $subs = (array) $this->input($field.'_sub', []);
        $entries = [];
        foreach (array_unique((array) $this->input($field, [])) as $name) {
            $parts = $key === 'DB' ? [] : self::split((string) ($subs[$name] ?? ''));
            $entries[] = implode(':', [$name, ...$parts]);
        }

        return $entries;
    }

    /** @return list<string> folders or accounts typed with spaces, commas or new lines */
    public static function split(string $text): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($text)) ?: [], fn ($v) => $v !== ''));
    }

    /** Every folder or account of a field matches PART. */
    private function parts(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (self::split((string) $value) as $part) {
                if (preg_match(self::PART, $part) !== 1 || str_contains($part, '..')) {
                    $fail(sprintf(__('Invalid entry: %s'), $part));

                    return;
                }
            }
        };
    }

    /** Every non-empty line must match $pattern and hold no "..". */
    private function lines(string $pattern): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            foreach (preg_split('/\R/', (string) $value) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match($pattern, $line) !== 1 || str_contains($line, '..')) {
                    $fail(sprintf(__('Invalid entry: %s'), $line));

                    return;
                }
            }
        };
    }
}
