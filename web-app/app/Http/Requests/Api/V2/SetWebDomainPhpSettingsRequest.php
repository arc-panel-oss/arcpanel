<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Php\PhpManager;

/**
 * PUT /users/{user}/web-domains/{domain}/php/settings (E19 §7): the
 * settings to change; a field left out stays, null resets it to the
 * profile's or php.ini's value.
 */
final class SetWebDomainPhpSettingsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $size = ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,6}[KMG]?$/', 'not_regex:/^0+[KMG]?$/'];

        return [
            /** `memory_limit`, a size such as `256M`, within the package maximum. */
            'memory_limit' => $size,
            /** `upload_max_filesize`, a size, within the package maximum. */
            'upload_max_filesize' => $size,
            /** `post_max_size`, a size, at least `upload_max_filesize` when both are set on the domain. */
            'post_max_size' => $size,
            /** `max_execution_time` in seconds, 0–86400, within the package maximum. */
            'max_execution_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            /** `max_input_vars`, 1–1000000, within the package maximum. */
            'max_input_vars' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            /** `display_errors`: `on` or `off`. */
            'display_errors' => ['sometimes', 'nullable', 'in:on,off'],
            /** `date.timezone`, a time zone such as `Europe/Berlin`. */
            'date_timezone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone:all'],
        ];
    }

    /**
     * The settings of the request, directive => value ('' resets).
     *
     * @return array<string, string>
     */
    public function wanted(): array
    {
        $wanted = [];
        foreach (array_keys(PhpManager::DOMAIN_SETTINGS) as $directive) {
            $field = str_replace('.', '_', $directive);
            if ($this->has($field)) {
                $wanted[$directive] = trim((string) $this->input($field));
            }
        }

        return $wanted;
    }
}
