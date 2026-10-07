<?php

use App\Http\Controllers\Api\V2\BackupController;
use App\Http\Controllers\Api\V2\CronController;
use App\Http\Controllers\Api\V2\DatabaseController;
use App\Http\Controllers\Api\V2\DnsController;
use App\Http\Controllers\Api\V2\MailAccountController;
use App\Http\Controllers\Api\V2\MailDomainController;
use App\Http\Controllers\Api\V2\NodeController;
use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\PackageController;
use App\Http\Controllers\Api\V2\UserController;
use App\Http\Controllers\Api\V2\TaskController;
use App\Http\Controllers\Api\V2\WebDomainController;
use Illuminate\Support\Facades\Route;

/*
| REST API v2 (docs/features/specs/2026-10-07-002-e34-api-v2-design.md),
| loaded by bootstrap/app.php under /api/v2 with the names api.v2.*:
| stateless, authenticated by an access key, answers JSON only. Every
| operation has a stable operationId (the @operationId tag of its
| controller method), which the SDK turns into method names.
*/

// The parameters of routes/web.php, for the API routes too
Route::pattern('user', '[[:alnum:]][-.\w]{0,28}[[:alnum:]]');
Route::pattern('domain', '[^/\s]{1,253}');
Route::pattern('package', '[[:alnum:]][-.\w]{0,99}');
Route::pattern('account', '[\w.-]{1,64}');
Route::pattern('database', '[\w-]{1,64}');
Route::pattern('backup', '[A-Za-z0-9][A-Za-z0-9_.-]{0,250}');
Route::pattern('snapshot', '[0-9a-f]{8,64}');
Route::pattern('alias', '[^/\s]{1,253}');
Route::pattern('version', '\d{1,3}\.\d{1,3}\.\d{1,3}');

Route::get('/me', MeController::class)->name('me');

Route::get('/tasks/{id}', [TaskController::class, 'show'])->where('id', '.+')->defaults('api_area', 'backup')->name('tasks.show');
Route::get('/system/tasks', [TaskController::class, 'system'])->defaults('api_area', 'backup')->name('system.tasks.index');

// Users (spec §6.1); {user} outside the principal's tree answers 404
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::post('/users', [UserController::class, 'store'])->middleware('not-readonly')->name('users.store');
Route::middleware('effective-user')->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/tasks', [TaskController::class, 'index'])->defaults('api_area', 'backup')->name('users.tasks.index');
    Route::middleware('not-readonly')->group(function () {
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/package', [UserController::class, 'package'])->name('users.package');
        Route::put('/users/{user}/password', [UserController::class, 'password'])->name('users.password');
        Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/unsuspend', [UserController::class, 'unsuspend'])->name('users.unsuspend');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Web domains (spec §6.3)
    Route::get('/users/{user}/web-domains', [WebDomainController::class, 'index'])->name('web-domains.index');
    Route::get('/users/{user}/web-domains/{domain}', [WebDomainController::class, 'show'])->name('web-domains.show');
    Route::middleware('not-readonly')->prefix('/users/{user}/web-domains')->name('web-domains.')->group(function () {
        Route::post('/', [WebDomainController::class, 'store'])->name('store');
        Route::patch('/{domain}', [WebDomainController::class, 'update'])->name('update');
        Route::delete('/{domain}', [WebDomainController::class, 'destroy'])->name('destroy');
        Route::post('/{domain}/suspend', [WebDomainController::class, 'suspend'])->name('suspend');
        Route::post('/{domain}/unsuspend', [WebDomainController::class, 'unsuspend'])->name('unsuspend');
        Route::post('/{domain}/rebuild', [WebDomainController::class, 'rebuild'])->name('rebuild');
        Route::post('/{domain}/purge-cache', [WebDomainController::class, 'purgeCache'])->name('purge-cache');
        Route::post('/{domain}/aliases', [WebDomainController::class, 'addAlias'])->name('aliases.store');
        Route::delete('/{domain}/aliases/{alias}', [WebDomainController::class, 'deleteAlias'])->name('aliases.destroy');
        Route::put('/{domain}/ssl', [WebDomainController::class, 'setSsl'])->name('ssl.update');
        Route::delete('/{domain}/ssl', [WebDomainController::class, 'deleteSsl'])->name('ssl.destroy');
        Route::post('/{domain}/letsencrypt', [WebDomainController::class, 'letsEncrypt'])->name('letsencrypt');
    });

    // DNS zones and records (E34 batch 2 spec §6.1)
    Route::get('/users/{user}/dns-zones', [DnsController::class, 'index'])->name('dns-zones.index');
    Route::get('/users/{user}/dns-zones/{domain}', [DnsController::class, 'show'])->name('dns-zones.show');
    Route::get('/users/{user}/dns-zones/{domain}/dnssec', [DnsController::class, 'dnssec'])->name('dns-zones.dnssec');
    Route::get('/users/{user}/dns-zones/{domain}/records', [DnsController::class, 'records'])->name('dns-zones.records.index');
    Route::get('/users/{user}/dns-zones/{domain}/records/{id}', [DnsController::class, 'showRecord'])->where('id', '[0-9]{1,9}')->name('dns-zones.records.show');
    Route::middleware('not-readonly')->prefix('/users/{user}/dns-zones')->name('dns-zones.')->group(function () {
        Route::post('/', [DnsController::class, 'store'])->name('store');
        Route::patch('/{domain}', [DnsController::class, 'update'])->name('update');
        Route::delete('/{domain}', [DnsController::class, 'destroy'])->name('destroy');
        Route::post('/{domain}/suspend', [DnsController::class, 'suspend'])->name('suspend');
        Route::post('/{domain}/unsuspend', [DnsController::class, 'unsuspend'])->name('unsuspend');
        Route::post('/{domain}/rebuild', [DnsController::class, 'rebuild'])->name('rebuild');
        Route::post('/{domain}/records', [DnsController::class, 'storeRecord'])->name('records.store');
        Route::patch('/{domain}/records/{id}', [DnsController::class, 'updateRecord'])->where('id', '[0-9]{1,9}')->name('records.update');
        Route::delete('/{domain}/records/{id}', [DnsController::class, 'destroyRecord'])->where('id', '[0-9]{1,9}')->name('records.destroy');
        Route::post('/{domain}/records/{id}/suspend', [DnsController::class, 'suspendRecord'])->where('id', '[0-9]{1,9}')->name('records.suspend');
        Route::post('/{domain}/records/{id}/unsuspend', [DnsController::class, 'unsuspendRecord'])->where('id', '[0-9]{1,9}')->name('records.unsuspend');
    });

    // Mail domains (E34 batch 2 spec §6.2)
    Route::get('/users/{user}/mail-domains', [MailDomainController::class, 'index'])->name('mail-domains.index');
    Route::get('/users/{user}/mail-domains/{domain}', [MailDomainController::class, 'show'])->name('mail-domains.show');
    Route::get('/users/{user}/mail-domains/{domain}/dns-records', [MailDomainController::class, 'dnsRecords'])->name('mail-domains.dns-records');
    Route::middleware('not-readonly')->prefix('/users/{user}/mail-domains')->name('mail-domains.')->group(function () {
        Route::post('/', [MailDomainController::class, 'store'])->name('store');
        Route::patch('/{domain}', [MailDomainController::class, 'update'])->name('update');
        Route::delete('/{domain}', [MailDomainController::class, 'destroy'])->name('destroy');
        Route::post('/{domain}/suspend', [MailDomainController::class, 'suspend'])->name('suspend');
        Route::post('/{domain}/unsuspend', [MailDomainController::class, 'unsuspend'])->name('unsuspend');
        Route::post('/{domain}/rebuild', [MailDomainController::class, 'rebuild'])->name('rebuild');
        Route::put('/{domain}/ssl', [MailDomainController::class, 'setSsl'])->name('ssl.update');
        Route::delete('/{domain}/ssl', [MailDomainController::class, 'deleteSsl'])->name('ssl.destroy');
        Route::post('/{domain}/letsencrypt', [MailDomainController::class, 'letsEncrypt'])->name('letsencrypt');
        Route::put('/{domain}/smtp-relay', [MailDomainController::class, 'setRelay'])->name('relay.update');
        Route::delete('/{domain}/smtp-relay', [MailDomainController::class, 'deleteRelay'])->name('relay.destroy');
    });

    // Mail accounts (E34 batch 2 spec §6.3)
    Route::get('/users/{user}/mail-domains/{domain}/accounts', [MailAccountController::class, 'index'])->name('mail-domains.accounts.index');
    Route::get('/users/{user}/mail-domains/{domain}/accounts/{account}', [MailAccountController::class, 'show'])->name('mail-domains.accounts.show');
    Route::middleware('not-readonly')->prefix('/users/{user}/mail-domains/{domain}/accounts')->name('mail-domains.accounts.')->group(function () {
        Route::post('/', [MailAccountController::class, 'store'])->name('store');
        Route::patch('/{account}', [MailAccountController::class, 'update'])->name('update');
        Route::put('/{account}/password', [MailAccountController::class, 'password'])->name('password');
        Route::delete('/{account}', [MailAccountController::class, 'destroy'])->name('destroy');
        Route::post('/{account}/suspend', [MailAccountController::class, 'suspend'])->name('suspend');
        Route::post('/{account}/unsuspend', [MailAccountController::class, 'unsuspend'])->name('unsuspend');
    });

    // Databases (E34 batch 2 spec §6.4)
    Route::get('/users/{user}/databases', [DatabaseController::class, 'index'])->name('databases.index');
    Route::get('/users/{user}/databases/{database}', [DatabaseController::class, 'show'])->name('databases.show');
    Route::middleware('not-readonly')->prefix('/users/{user}/databases')->name('databases.')->group(function () {
        Route::post('/', [DatabaseController::class, 'store'])->name('store');
        Route::patch('/{database}', [DatabaseController::class, 'update'])->name('update');
        Route::put('/{database}/password', [DatabaseController::class, 'password'])->name('password');
        Route::delete('/{database}', [DatabaseController::class, 'destroy'])->name('destroy');
        Route::post('/{database}/suspend', [DatabaseController::class, 'suspend'])->name('suspend');
        Route::post('/{database}/unsuspend', [DatabaseController::class, 'unsuspend'])->name('unsuspend');
        Route::post('/{database}/rebuild', [DatabaseController::class, 'rebuild'])->name('rebuild');
        Route::post('/{database}/dump', [DatabaseController::class, 'dump'])->name('dump');
    });

    // Backups (E34 batch 3 spec §6.1)
    Route::get('/users/{user}/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::get('/users/{user}/backups/{backup}', [BackupController::class, 'show'])->name('backups.show');
    Route::get('/users/{user}/backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');
    Route::middleware('not-readonly')->group(function () {
        Route::post('/users/{user}/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::post('/users/{user}/backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
        Route::delete('/users/{user}/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
        Route::post('/users/{user}/snapshots', [BackupController::class, 'createSnapshot'])->name('snapshots.store');
        Route::post('/users/{user}/snapshots/{snapshot}/restore', [BackupController::class, 'restoreSnapshot'])->name('snapshots.restore');
        Route::delete('/users/{user}/snapshots/{snapshot}', [BackupController::class, 'destroySnapshot'])->name('snapshots.destroy');
        Route::put('/users/{user}/backup-exclusions', [BackupController::class, 'updateExclusions'])->name('backup-exclusions.update');
    });

    Route::get('/users/{user}/snapshots', [BackupController::class, 'listSnapshots'])->name('snapshots.index');
    Route::get('/users/{user}/snapshots/{snapshot}', [BackupController::class, 'getSnapshot'])->name('snapshots.show');
    Route::get('/users/{user}/snapshots/{snapshot}/files', [BackupController::class, 'listSnapshotFiles'])->name('snapshots.files');
    Route::get('/users/{user}/backup-exclusions', [BackupController::class, 'showExclusions'])->name('backup-exclusions.show');

    // Node.js runtimes (E34 batch 4 spec §4.1)
    Route::get('/users/{user}/node', [NodeController::class, 'show'])->name('node.show');
    Route::get('/users/{user}/node/releases', [NodeController::class, 'releases'])->name('node.releases');
    Route::get('/users/{user}/node/versions', [NodeController::class, 'versions'])->name('node.versions.index');
    Route::get('/users/{user}/node/versions/{version}/packages', [NodeController::class, 'packages'])->name('node.packages.index');
    Route::middleware('not-readonly')->prefix('/users/{user}/node')->name('node.')->group(function () {
        Route::put('/', [NodeController::class, 'setEnabled'])->name('enabled');
        Route::post('/versions', [NodeController::class, 'installVersion'])->name('versions.store');
        Route::put('/versions/{version}/default', [NodeController::class, 'setDefault'])->name('versions.default');
        Route::delete('/versions/{version}', [NodeController::class, 'destroyVersion'])->name('versions.destroy');
        Route::post('/versions/{version}/packages', [NodeController::class, 'installPackage'])->name('packages.store');
        Route::post('/versions/{version}/packages/update', [NodeController::class, 'updatePackages'])->name('packages.update');
        Route::post('/versions/{version}/packages/check', [NodeController::class, 'checkPackages'])->name('packages.check');
        Route::delete('/versions/{version}/packages/{package}', [NodeController::class, 'destroyPackage'])
            ->where('package', '(@[a-z0-9][a-z0-9._~-]*/)?[a-z0-9][a-z0-9._~-]*')
            ->name('packages.destroy');
    });

    // Cron jobs (E34 batch 2 spec §6.5)
    Route::get('/users/{user}/cron-jobs', [CronController::class, 'index'])->name('cron-jobs.index');
    Route::get('/users/{user}/cron-jobs/{id}', [CronController::class, 'show'])->where('id', '[0-9]{1,9}')->name('cron-jobs.show');
    Route::get('/users/{user}/cron-reports', [CronController::class, 'reports'])->name('cron-reports.show');
    Route::middleware('not-readonly')->group(function () {
        Route::post('/users/{user}/cron-jobs', [CronController::class, 'store'])->name('cron-jobs.store');
        Route::patch('/users/{user}/cron-jobs/{id}', [CronController::class, 'update'])->where('id', '[0-9]{1,9}')->name('cron-jobs.update');
        Route::delete('/users/{user}/cron-jobs/{id}', [CronController::class, 'destroy'])->where('id', '[0-9]{1,9}')->name('cron-jobs.destroy');
        Route::post('/users/{user}/cron-jobs/{id}/suspend', [CronController::class, 'suspend'])->where('id', '[0-9]{1,9}')->name('cron-jobs.suspend');
        Route::post('/users/{user}/cron-jobs/{id}/unsuspend', [CronController::class, 'unsuspend'])->where('id', '[0-9]{1,9}')->name('cron-jobs.unsuspend');
        Route::put('/users/{user}/cron-reports', [CronController::class, 'setReports'])->name('cron-reports.update');
    });
});

// Packages (spec §6.2); a package the principal may not assign answers 404
Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
Route::get('/packages/{package}', [PackageController::class, 'show'])->name('packages.show');
Route::middleware('not-readonly')->group(function () {
    Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
    Route::patch('/packages/{package}', [PackageController::class, 'update'])->name('packages.update');
    Route::post('/packages/{package}/copy', [PackageController::class, 'copy'])->name('packages.copy');
    Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
});

// Area of each route (E34 batch 2 spec D30). A test gives its key exactly
// that area's set. More specific paths win over /users and /packages.
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/v2/') || isset($route->defaults['api_area'])) {
        continue;
    }
    $area = match (true) {
        str_contains($uri, '/web-domains') => 'web',
        str_contains($uri, '/dns-zones') => 'dns',
        str_contains($uri, '/mail-domains') => 'mail',
        str_contains($uri, '/databases') => 'db',
        str_contains($uri, '/cron-jobs'), str_contains($uri, '/cron-reports') => 'cron',
        str_contains($uri, '/backups'), str_contains($uri, '/snapshots'), str_contains($uri, '/backup-exclusions') => 'backup',
        str_contains($uri, '/tasks') => 'backup',
        str_contains($uri, '/node') => 'apps',
        str_starts_with($uri, 'api/v2/users'), str_starts_with($uri, 'api/v2/packages') => 'accounts',
        default => null,
    };
    if ($area !== null) {
        $route->defaults('api_area', $area);
    }
}
