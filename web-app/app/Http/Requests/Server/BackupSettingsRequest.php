<?php

namespace App\Http\Requests\Server;

/** Backups tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class BackupSettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $keep = ['required_if_accepted:incremental', 'nullable', 'integer', 'min:-1', 'max:9999'];

        return [
            'mode' => ['required', 'in:gzip,zstd'],
            'level' => ['required', 'integer', 'between:1,19'],
            'remote_type' => ['required_if_accepted:remote', 'nullable', 'in:ftp,sftp,b2,rclone'],
            'host' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:128', 'not_regex:/[\'"\s\\\\]/'],
            'password' => ['nullable', 'string', 'max:4096'],
            'path' => ['nullable', 'string', 'max:255', 'regex:/^[\w\/.:@~+-]*$/'],
            'bucket' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9-]+$/'],
            'key_id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'repo' => ['required_if_accepted:incremental', 'nullable', 'string', 'max:1024', 'not_regex:/[\'"\s\\\\]/'],
            'snapshots' => $keep,
            'keep_daily' => $keep,
            'keep_weekly' => $keep,
            'keep_monthly' => $keep,
            'keep_yearly' => $keep,
        ];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        $type = (string) $this->input('remote_type', '');

        return [
            'local' => $this->boolean('local'),
            'mode' => (string) $this->input('mode'),
            'level' => (int) $this->input('level'),
            'remote' => $this->boolean('remote'),
            'remote_type' => $type,
            'host' => trim((string) $this->input('host')),
            'port' => $this->filled('port') ? (string) (int) $this->input('port') : ($type === 'ftp' ? '21' : ($type === 'sftp' ? '22' : '')),
            'username' => trim((string) $this->input('username')),
            'path' => trim((string) $this->input('path')),
            'bucket' => trim((string) $this->input('bucket')),
            'key_id' => trim((string) $this->input('key_id')),
            // ftp/sftp password or b2 application key
            'remote_secret' => $this->filled('password') ? (string) $this->input('password') : null,
            'incremental' => $this->boolean('incremental'),
            'repo' => trim((string) $this->input('repo')),
            'snapshots' => (string) (int) $this->input('snapshots', 30),
            'keep_daily' => (string) (int) $this->input('keep_daily', -1),
            'keep_weekly' => (string) (int) $this->input('keep_weekly', -1),
            'keep_monthly' => (string) (int) $this->input('keep_monthly', -1),
            'keep_yearly' => (string) (int) $this->input('keep_yearly', -1),
        ];
    }
}
