<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The php.ini of a version and SAPI (`v-list-sys-php-ini VERSION SAPI json`,
 * E41 §6): the directives of the form with their value and source, the
 * number of web domains whose PHP-FPM profile overrides each, and the file
 * itself when asked for (`?raw=1`).
 *
 * @property array{version: string, sapi: string, file: string, size: int, mtime: string, values: array<string, array{value: string, source: string, line: int, overrides: int}>, content?: string|null, findings?: list<array<string, mixed>>} $resource
 */
final class PhpIniResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $values = [];
        foreach ($this->resource['values'] as $directive => $value) {
            $values[$directive] = [
                'value' => $value['value'],
                'source' => $value['source'],
                'line' => $value['line'] > 0 ? $value['line'] : null,
                'profile_overrides' => $value['overrides'],
            ];
        }

        return [
            /** @var string */
            'version' => $this->resource['version'],
            /** @var string `fpm` or `cli` */
            'sapi' => $this->resource['sapi'],
            /** @var string the path of the file */
            'file' => $this->resource['file'],
            /** @var int bytes */
            'size' => $this->resource['size'],
            /** @var string|null `YYYY-MM-DD HH:MM:SS` */
            'modified_at' => $this->resource['mtime'] !== '' ? $this->resource['mtime'] : null,
            /**
             * The directives of the form: value, source (`file` or `default`,
             * PHP's built-in value), line in the file, and the number of web
             * domains whose profile sets their own value (PHP-FPM only).
             *
             * @var array<string, array{value: string, source: string, line: int|null, profile_overrides: int}>
             */
            'values' => $values,
            /** @var string|null the file itself, with `?raw=1` */
            'content' => $this->when(array_key_exists('content', $this->resource), fn () => $this->resource['content']),
            /** @var list<array{line: int, level: string, directive: string, message: string}> warnings of the saved file */
            'findings' => $this->when(array_key_exists('findings', $this->resource), fn () => $this->resource['findings']),
        ];
    }
}
