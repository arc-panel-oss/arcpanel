<?php

use App\Arc\Secret;
use App\Services\Server\ServerSettings;

// Secrets of the server settings never travel as plain arguments (E7 batch 3 spec §8.2, §17).

beforeEach(function () {
    $this->fake = fakeArc(['SMTP_RELAY' => 'false', 'BACKUP_SYSTEM' => 'local', 'BACKUP_INCREMENTAL' => 'no', 'BACKUP_MODE' => 'zstd', 'BACKUP_GZIP' => '4']);
    $this->settings = app(ServerSettings::class);
});

it('passes the relay, database host and remote backup passwords as files', function () {
    $relay = $this->settings->planMail(['webmail_alias' => null, 'relay' => true, 'relay_host' => 'smtp.example.net', 'relay_port' => '587', 'relay_user' => 'me', 'relay_password' => 'pw']);
    expect($relay[0]->args[2])->toBeInstanceOf(Secret::class)->and($relay[0]->args[2]->viaFile)->toBeTrue();

    $host = $this->settings->planDatabaseHostPassword('mysql', 'localhost', 'root', 'pw');
    expect($host[0]->args[3])->toBeInstanceOf(Secret::class)->and($host[0]->args[3]->viaFile)->toBeTrue();

    $backups = $this->settings->planBackups([
        'local' => true, 'mode' => 'zstd', 'level' => 4, 'remote' => true, 'remote_type' => 'ftp', 'host' => 'ftp.example.net', 'port' => '21',
        'username' => 'arc', 'path' => '/b', 'bucket' => '', 'key_id' => '', 'remote_secret' => 'pw', 'incremental' => true,
        'repo' => 'sftp:arc:pw@host:/r', 'snapshots' => '30', 'keep_daily' => '-1', 'keep_weekly' => '-1', 'keep_monthly' => '-1', 'keep_yearly' => '-1',
    ]);
    $add = collect($backups)->firstWhere('command', 'v-add-backup-host');
    $restic = collect($backups)->firstWhere('command', 'v-add-backup-host-restic');
    expect($add->args[3])->toBeInstanceOf(Secret::class)->and($add->args[3]->viaFile)->toBeTrue()
        ->and($restic->args[0])->toBeInstanceOf(Secret::class)
        ->and((string) $restic->args[0])->toBe('******');
});

it('passes the panel certificate and key as a secret directory', function () {
    $steps = $this->settings->planSsl("-----BEGIN CERTIFICATE-----\r\nA\r\n-----END CERTIFICATE-----\r\n\r\n", 'KEY');

    expect($steps[0]->args[0])->toBeInstanceOf(Secret::class)
        ->and($steps[0]->args[0]->isDirectory())->toBeTrue()
        ->and($steps[0]->args[0]->files())->toBe([
            'certificate.crt' => "-----BEGIN CERTIFICATE-----\nA\n-----END CERTIFICATE-----\n",
            'certificate.key' => "KEY\n",
        ]);
});
