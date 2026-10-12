<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PHP-FPM profile (`v-list-sys-php-profiles json`, E41 §7): the process
 * manager and PHP limits of the pools that use it.
 *
 * @property array{name: string, values: array<string, string>, builtin: bool, domains: int, packages: list<string>} $resource
 */
final class PhpProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource['values'];
        $number = fn (string $key) => $v[$key] !== '' ? (int) $v[$key] : null;
        $text = fn (string $key) => $v[$key] !== '' ? $v[$key] : null;

        return [
            /** @var string */
            'name' => $this->resource['name'],
            /** @var string|null */
            'description' => $text('DESCRIPTION'),
            /** @var string `ondemand`, `dynamic` or `static` */
            'pm' => $v['PM'],
            /** @var int pm.max_children */
            'max_children' => (int) $v['MAX_CHILDREN'],
            /** @var int|null pm.start_servers (dynamic) */
            'start_servers' => $number('START_SERVERS'),
            /** @var int|null pm.min_spare_servers (dynamic) */
            'min_spare' => $number('MIN_SPARE'),
            /** @var int|null pm.max_spare_servers (dynamic) */
            'max_spare' => $number('MAX_SPARE'),
            /** @var int pm.max_requests; 0 never recycles a process */
            'max_requests' => (int) $v['MAX_REQUESTS'],
            /** @var string|null pm.process_idle_timeout (ondemand), for example `10s` */
            'idle_timeout' => $text('IDLE_TIMEOUT'),
            /** @var string|null request_terminate_timeout, for example `300s` */
            'request_terminate_timeout' => $text('REQUEST_TERMINATE_TIMEOUT'),
            /**
             * PHP limits written as php_admin_value; null takes the value of php.ini.
             *
             * @var array{memory_limit: string|null, upload_max_filesize: string|null, post_max_size: string|null, max_execution_time: int|null, max_input_vars: int|null}
             */
            'limits' => [
                'memory_limit' => $text('MEMORY_LIMIT'),
                'upload_max_filesize' => $text('UPLOAD_MAX_FILESIZE'),
                'post_max_size' => $text('POST_MAX_SIZE'),
                'max_execution_time' => $number('MAX_EXECUTION_TIME'),
                'max_input_vars' => $number('MAX_INPUT_VARS'),
            ],
            /** @var bool one of the shipped profiles; `default` cannot be deleted */
            'builtin' => $this->resource['builtin'],
            /** @var int web domain pools that use it */
            'domains' => $this->resource['domains'],
            /** @var list<string> packages that allow it */
            'packages' => $this->resource['packages'],
        ];
    }
}
