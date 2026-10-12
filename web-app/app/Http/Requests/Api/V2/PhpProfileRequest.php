<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Php\PhpManager;
use Illuminate\Validation\Rule;

/**
 * POST /system/php/profiles and PUT /system/php/profiles/{name} (E41 §7.1).
 * The shape is checked here; the command checks the relations (spare
 * servers, upload limits) and answers with the first error.
 */
final class PhpProfileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $size = ['nullable', 'string', 'max:16', 'regex:/^\d{1,6}[KMG]?$/'];

        return [
            /** Lower-case letters, digits and `-`, up to 32 characters. Only when creating. */
            'name' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
            'description' => ['nullable', 'string', 'max:100', 'not_regex:/[\'\\\\]/'],
            /** `ondemand`, `dynamic` or `static`. */
            'pm' => ['required', Rule::in(PhpManager::PROFILE_MANAGERS)],
            'max_children' => ['required', 'integer', 'min:1', 'max:500'],
            /** Dynamic only: min_spare ≤ start_servers ≤ max_spare ≤ max_children. */
            'start_servers' => ['nullable', 'integer', 'min:1', 'max:500', 'required_if:pm,dynamic'],
            'min_spare' => ['nullable', 'integer', 'min:1', 'max:500', 'required_if:pm,dynamic'],
            'max_spare' => ['nullable', 'integer', 'min:1', 'max:500', 'required_if:pm,dynamic'],
            /** 0 never recycles a process. */
            'max_requests' => ['required', 'integer', 'min:0', 'max:100000'],
            /** Ondemand only, for example `10s`. */
            'idle_timeout' => ['nullable', 'string', 'regex:/^\d{1,5}s$/'],
            /** For example `300s`; null for none. */
            'request_terminate_timeout' => ['nullable', 'string', 'regex:/^\d{1,5}s$/'],
            /** PHP limits written as php_admin_value; null takes the value of php.ini. */
            'limits' => ['nullable', 'array'],
            /** A size such as `256M`, or `-1` for no limit. */
            'limits.memory_limit' => ['nullable', 'string', 'max:16', 'regex:/^(-1|\d{1,6}[KMG]?)$/'],
            'limits.upload_max_filesize' => $size,
            /** At least upload_max_filesize. */
            'limits.post_max_size' => $size,
            'limits.max_execution_time' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'limits.max_input_vars' => ['nullable', 'integer', 'min:1', 'max:9999999'],
        ];
    }

    /**
     * The values as profile keys; fields the process manager does not use stay empty.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $pm = (string) $this->input('pm');
        $get = fn (string $key) => trim((string) ($this->input($key) ?? ''));

        return [
            'DESCRIPTION' => $get('description'),
            'PM' => $pm,
            'MAX_CHILDREN' => $get('max_children'),
            'START_SERVERS' => $pm === 'dynamic' ? $get('start_servers') : '',
            'MIN_SPARE' => $pm === 'dynamic' ? $get('min_spare') : '',
            'MAX_SPARE' => $pm === 'dynamic' ? $get('max_spare') : '',
            'MAX_REQUESTS' => $get('max_requests'),
            'IDLE_TIMEOUT' => $pm === 'ondemand' ? $get('idle_timeout') : '',
            'REQUEST_TERMINATE_TIMEOUT' => $get('request_terminate_timeout'),
            'MEMORY_LIMIT' => $get('limits.memory_limit'),
            'UPLOAD_MAX_FILESIZE' => $get('limits.upload_max_filesize'),
            'POST_MAX_SIZE' => $get('limits.post_max_size'),
            'MAX_EXECUTION_TIME' => $get('limits.max_execution_time'),
            'MAX_INPUT_VARS' => $get('limits.max_input_vars'),
        ];
    }
}
