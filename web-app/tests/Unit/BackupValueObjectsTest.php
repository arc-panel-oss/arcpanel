<?php

use App\Services\Backups\BackupDestination;
use App\Services\Backups\BackupJob;
use App\Services\Backups\BackupJobRun;
use App\Services\Backups\BackupRun;
use App\Services\Backups\BackupSchedule;

// Value objects of the Backups hub (E48 spec §4, §5, §8.3).

it('parses, rebuilds and describes every kind of schedule', function (string $schedule, string $preset, string $words) {
    $parsed = BackupSchedule::parse($schedule);

    expect($parsed->preset)->toBe($preset)
        ->and($parsed->value())->toBe($schedule)
        ->and($parsed->describe())->toBe($words);
})->with([
    ['hourly:07', 'hourly', 'Every hour at minute 07'],
    ['daily:05:10', 'daily', 'Daily at 05:10'],
    ['weekly:1:02:30', 'weekly', 'Every Monday at 02:30'],
    ['monthly:28:23:59', 'monthly', 'Monthly on day 28 at 23:59'],
    ['cron:0 */6 * * 1-5', 'cron', 'Cron 0 */6 * * 1-5'],
]);

it('builds a schedule from the form and squeezes the spaces of a cron expression', function () {
    expect(BackupSchedule::fromForm('daily', 3, 5, 1, 1, ''))->toBe('daily:03:05')
        ->and(BackupSchedule::fromForm('monthly', 1, 0, 1, 9, ''))->toBe('monthly:09:01:00')
        ->and(BackupSchedule::fromForm('cron', 0, 0, 1, 1, "  30\t 2 * * *  "))->toBe('cron:30 2 * * *')
        // an unknown schedule reads as the default of the conversion
        ->and(BackupSchedule::parse('every:now')->value())->toBe('daily:05:10');
});

it('reads targets and their retention, and the accounts a job covers', function () {
    $job = BackupJob::fromArray('179267621466', ['NAME' => 'Nightly', 'ENGINE' => 'snapshot', 'SCOPE' => 'packages:gold,silver', 'EXCLUDE_USERS' => 'carol',
        'SCHEDULE' => 'daily:05:40', 'TARGETS' => 'local:30:-1:-1:-1:-1,offsite:5:7:4', 'STATE' => 'paused', 'RUNNING' => 'yes']);

    expect($job->targets)->toBe([
        ['destination' => 'local', 'keepLast' => 30, 'daily' => -1, 'weekly' => -1, 'monthly' => -1, 'yearly' => -1],
        ['destination' => 'offsite', 'keepLast' => 5, 'daily' => 7, 'weekly' => 4, 'monthly' => -1, 'yearly' => -1],
    ])
        ->and(BackupJob::targetsArg($job->targets))->toBe('local:30:-1:-1:-1:-1,offsite:5:7:4:-1:-1')
        ->and($job->destinations())->toBe(['local', 'offsite'])
        ->and($job->enabled)->toBeFalse()
        ->and($job->running)->toBeTrue()
        ->and($job->scopeLabel())->toBe('Packages: gold, silver')
        ->and($job->covers('bob', 'gold'))->toBeTrue()
        ->and($job->covers('carol', 'gold'))->toBeFalse()
        ->and($job->covers('dave', 'default'))->toBeFalse();
});

it('shows where a destination stores backups without its secrets', function () {
    $sftp = BackupDestination::fromArray('box', ['DRIVER' => 'sftp', 'HOST' => 'box.example.net', 'USERNAME' => 'u1', 'PATH' => 'arc', 'ENCRYPT' => 'yes',
        'SECRETS' => 'PASSWORD PRIVATE_KEY', 'JOBS' => '1792676214,1792676215', 'STATE' => '']);
    $s3 = BackupDestination::fromArray('s3', ['DRIVER' => 's3', 'BUCKET' => 'arc', 'PATH' => '/server1', 'ENCRYPT' => 'no', 'KIT' => '']);

    expect($sftp->location())->toBe('u1@box.example.net:arc')
        ->and($sftp->secrets)->toBe(['PASSWORD', 'PRIVATE_KEY'])
        ->and($sftp->jobs)->toBe(['1792676214', '1792676215'])
        ->and($sftp->state)->toBe('unchecked')
        ->and($sftp->needsKit())->toBeTrue()
        ->and($sftp->isRemote())->toBeTrue()
        ->and($s3->location())->toBe('arc/server1')
        ->and($s3->needsKit())->toBeFalse()
        ->and(BackupDestination::authorizeBackend('gdrive'))->toBe('drive')
        ->and(BackupDestination::authorizeBackend('sftp'))->toBeNull();
});

it('gives the state of runs a badge tone and splits the state per destination', function () {
    expect(BackupJobRun::statusTone('success'))->toBe('ok')
        ->and(BackupJobRun::statusTone('partial'))->toBe('warn')
        ->and(BackupJobRun::statusTone('failing'))->toBe('danger')
        ->and(BackupJobRun::statusTone('running'))->toBe('info')
        ->and(BackupJobRun::statusTone('unknown'))->toBe('neutral');

    $run = BackupRun::fromArray('bob', '17926789981107', ['STATUS' => 'succeeded', 'JOB' => '179267621466', 'DESTS' => 'local:ok,offsite:failed']);
    expect($run->job)->toBe('179267621466')
        ->and($run->destinationStates())->toBe(['local' => 'ok', 'offsite' => 'failed']);
});
