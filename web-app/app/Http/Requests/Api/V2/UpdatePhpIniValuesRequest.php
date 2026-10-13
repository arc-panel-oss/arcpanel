<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Php\PhpManager;
use Illuminate\Validation\Validator;

/**
 * PATCH /system/php/versions/{php}/ini/{sapi} (E41 §6.2, §9): directives of
 * the php.ini form.
 */
final class UpdatePhpIniValuesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /**
             * Directives of the form and their new values, for example
             * `{"memory_limit": "256M", "display_errors": false}`. Switches take
             * booleans, `disable_functions` a list of function names.
             *
             * @var array<string, string|bool|list<string>>
             */
            'values' => ['required', 'array', 'min:1'],
        ];
    }

    /** Only directives of the form, each with a value of its kind. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('values', []) as $directive => $value) {
                $field = 'values.'.$directive;
                if (! in_array($directive, PhpManager::FORM_DIRECTIVES, true)) {
                    $validator->errors()->add($field, sprintf(__('%s is not a directive of the form.'), $directive));
                } elseif (in_array($directive, PhpManager::BOOLEAN_DIRECTIVES, true)) {
                    if (! is_bool($value)) {
                        $validator->errors()->add($field, __('The value must be true or false.'));
                    }
                } elseif ($directive === 'disable_functions') {
                    if (! is_array($value) || array_filter($value, fn ($name) => ! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name) !== 1) !== []) {
                        $validator->errors()->add($field, __('The value must be a list of function names.'));
                    }
                } elseif (! is_string($value) && ! is_int($value) || preg_match('/["$\\\\]/', (string) $value) === 1 || strlen((string) $value) > 4096) {
                    $validator->errors()->add($field, __('The value must be text without quotes, $ or backslashes.'));
                }
            }
        });
    }

    /**
     * The values as directive => php.ini text.
     *
     * @return array<string, string>
     */
    public function directives(): array
    {
        $values = [];
        foreach ((array) $this->input('values', []) as $directive => $value) {
            $values[(string) $directive] = match (true) {
                is_bool($value) => $value ? 'On' : 'Off',
                is_array($value) => implode(',', array_map('strval', $value)),
                default => trim((string) $value),
            };
        }

        return $values;
    }
}
