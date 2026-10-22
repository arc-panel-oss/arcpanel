<?php

namespace App\Http\Requests\Server;

/** Settings of the Backups hub (E48 spec §8.2); BackupPolicy and ServerPolicy authorize. */
final class BackupHubSettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:gzip,zstd'],
            'level' => ['required', 'integer', 'between:1,19'],
            'temp' => ['required', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/-]+$/', 'not_regex:/(^|\/)\.\.?(\/|$)|\/\//',
                'not_regex:/^\/(home|proc|sys|dev|run|etc|usr|bin|sbin|lib|boot|root)(\/|$)/'],
            'parallel' => ['required', 'integer', 'between:1,16'],
            'la_limit' => ['required', 'integer', 'between:1,512'],
            'report' => ['required', 'in:failures,daily,off'],
        ];
    }

    /** @return array{mode: string, level: int, temp: string, parallel: int, la_limit: int, report: string, suspended: bool} */
    public function values(): array
    {
        return [
            'mode' => (string) $this->input('mode'),
            'level' => (int) $this->input('level'),
            'temp' => rtrim((string) $this->input('temp'), '/') ?: '/',
            'parallel' => (int) $this->input('parallel'),
            'la_limit' => (int) $this->input('la_limit'),
            'report' => (string) $this->input('report'),
            'suspended' => $this->boolean('suspended'),
        ];
    }
}
