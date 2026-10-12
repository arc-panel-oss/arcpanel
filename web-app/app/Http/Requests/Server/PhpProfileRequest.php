<?php

namespace App\Http\Requests\Server;

use App\Services\Php\PhpManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A PHP-FPM profile of the profile form (E41 §7.1). The shape is checked
 * here; the command checks the relations (spare servers, upload limits) and
 * answers with the first error.
 */
final class PhpProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $size = ['nullable', 'string', 'max:16', 'regex:/^(-1|\d{1,6}[KMG]?)$/'];
        $count = ['nullable', 'integer', 'min:1', 'max:500'];
        $seconds = ['nullable', 'string', 'max:8', 'regex:/^\d{1,5}s$/'];

        return [
            'name' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
            'description' => ['nullable', 'string', 'max:100', 'not_regex:/[\'\\\\\r\n]/'],
            'pm' => ['required', Rule::in(PhpManager::PROFILE_MANAGERS)],
            'max_children' => ['required', 'integer', 'min:1', 'max:500'],
            'start_servers' => [...$count, 'required_if:pm,dynamic'],
            'min_spare' => [...$count, 'required_if:pm,dynamic'],
            'max_spare' => [...$count, 'required_if:pm,dynamic'],
            'max_requests' => ['required', 'integer', 'min:0', 'max:100000'],
            'idle_timeout' => $seconds,
            'request_terminate_timeout' => $seconds,
            'memory_limit' => $size,
            'upload_max_filesize' => ['nullable', 'string', 'max:16', 'regex:/^\d{1,6}[KMG]?$/'],
            'post_max_size' => ['nullable', 'string', 'max:16', 'regex:/^\d{1,6}[KMG]?$/'],
            'max_execution_time' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'max_input_vars' => ['nullable', 'integer', 'min:1', 'max:9999999'],
        ];
    }

    /**
     * The values as profile keys; the fields the process manager does not
     * use are left empty.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $pm = (string) $this->input('pm');
        $values = [];
        foreach (PhpManager::PROFILE_KEYS as $key) {
            $values[$key] = trim((string) $this->input(strtolower($key), ''));
        }
        if ($pm !== 'dynamic') {
            $values['START_SERVERS'] = $values['MIN_SPARE'] = $values['MAX_SPARE'] = '';
        }
        if ($pm !== 'ondemand') {
            $values['IDLE_TIMEOUT'] = '';
        }

        return $values;
    }
}
