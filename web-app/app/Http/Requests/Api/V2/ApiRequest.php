<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Base of the API v2 requests: JSON bodies, authorization by the controller
 * through the policies, validation errors answered as E_VALIDATION by
 * App\Api\ErrorRenderer (never a redirect).
 *
 * Every field the rules name (and everything inside it) is refused when it
 * holds a line break or a NUL byte, keys included: a command argument cannot
 * carry them (App\Arc\CommandArguments), and a regex rule ending in `$`
 * accepts a final line break. The check reads the JSON body as sent, before
 * TrimStrings. Fields of multiLineFields() may hold line breaks.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fields whose value may span lines (PEM certificates, message texts).
     *
     * @return list<string>
     */
    protected function multiLineFields(): array
    {
        return [];
    }

    protected function getValidatorInstance()
    {
        if ($this->validator !== null) {
            return $this->validator;
        }
        $validator = parent::getValidatorInstance();
        $validator->after(fn (Validator $validator) => $this->refuseControlCharacters($validator));

        return $validator;
    }

    private function refuseControlCharacters(Validator $validator): void
    {
        $patterns = array_map('strval', array_keys($this->container->call([$this, 'rules'])));
        $raw = $this->isJson() ? json_decode((string) $this->getContent(), true) : null;
        $errors = [];
        foreach ([is_array($raw) ? $raw : [], $this->all()] as $input) {
            $this->collectControlCharacters($input, '', $patterns, false, $errors);
        }
        foreach ($errors as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $patterns  the rule keys
     * @param  array<string, string>  $errors
     */
    private function collectControlCharacters(array $input, string $prefix, array $patterns, bool $checked, array &$errors): void
    {
        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $inRules = $checked || Str::is($patterns, $path);
            if (! $inRules) {
                if (is_array($value)) {
                    $this->collectControlCharacters($value, $path, $patterns, false, $errors);
                }

                continue;
            }
            $multiLine = Str::is($this->multiLineFields(), $path);
            if (is_string($key) && preg_match('/[\r\n\0]/', $key) === 1) {
                $errors[$prefix === '' ? $path : $prefix] ??= __('Line breaks and NUL bytes are not allowed here.');
            } elseif (is_array($value)) {
                $this->collectControlCharacters($value, $path, $patterns, true, $errors);
            } elseif (is_string($value) && preg_match($multiLine ? '/\0/' : '/[\r\n\0]/', $value) === 1) {
                $errors[$path] ??= $multiLine ? __('NUL bytes are not allowed here.') : __('Line breaks and NUL bytes are not allowed here.');
            }
        }
    }
}
