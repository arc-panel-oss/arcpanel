<?php

namespace App\Http\Requests\Server;

use App\Services\Php\PhpManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A PHP-FPM profile of the profile form (E41 §7.1). The shape and the
 * relations of the form (spare servers, upload and POST size) are checked
 * here, so each error shows at its field; the command checks them again.
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
     * The relations between the fields, once their shape is valid.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('pm') === 'dynamic') {
                $max = (int) $this->input('max_children');
                $start = (int) $this->input('start_servers');
                $minSpare = (int) $this->input('min_spare');
                $maxSpare = (int) $this->input('max_spare');
                if ($minSpare > $start) {
                    $validator->errors()->add('min_spare', __('At most the processes at start.'));
                }
                if ($start > $maxSpare) {
                    $validator->errors()->add('start_servers', __('At most the maximum spare processes.'));
                }
                if ($maxSpare > $max) {
                    $validator->errors()->add('max_spare', __('At most the maximum processes.'));
                }
            }
            $upload = self::bytes((string) $this->input('upload_max_filesize', ''));
            $post = self::bytes((string) $this->input('post_max_size', ''));
            if ($upload !== null && $post !== null && $post < $upload) {
                $validator->errors()->add('post_max_size', __('At least the upload size.'));
            }
        }];
    }

    /** A php.ini size (128M) in bytes, or null when empty. */
    private static function bytes(string $size): ?int
    {
        if (! preg_match('/^(\d+)([KMG]?)$/', trim($size), $m)) {
            return null;
        }

        return (int) $m[1] * ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][$m[2]];
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
