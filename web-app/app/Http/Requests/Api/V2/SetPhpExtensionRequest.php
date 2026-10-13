<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/php/versions/{php}/extensions/{name} (E41 §9).
 */
final class SetPhpExtensionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** `true` installs the extension as the system task (`202`); an installed one stays. Removing is `DELETE`. */
            'installed' => ['sometimes', 'boolean', 'accepted'],
            /** Turn the module on or off for PHP-FPM. */
            'fpm' => ['sometimes', 'boolean'],
            /** Turn the module on or off for the command line. */
            'cli' => ['sometimes', 'boolean'],
        ];
    }
}
