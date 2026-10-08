<?php

use App\Http\Controllers\Api\V2\AccessKeyController;
use App\Http\Controllers\Api\V2\BackupController;
use App\Http\Controllers\Api\V2\CronController;
use App\Http\Controllers\Api\V2\DatabaseController;
use App\Http\Controllers\Api\V2\DnsController;
use App\Http\Controllers\Api\V2\DockerAppController;
use App\Http\Controllers\Api\V2\DockerCatalogController;
use App\Http\Controllers\Api\V2\DockerEngineController;
use App\Http\Controllers\Api\V2\FileController;
use App\Http\Controllers\Api\V2\FileTransferController;
use App\Http\Controllers\Api\V2\IpController;
use App\Http\Controllers\Api\V2\LogController;
use App\Http\Controllers\Api\V2\MailAccountController;
use App\Http\Controllers\Api\V2\MailDomainController;
use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\NodeAppController;
use App\Http\Controllers\Api\V2\NodeController;
use App\Http\Controllers\Api\V2\NotificationController;
use App\Http\Controllers\Api\V2\PackageController;
use App\Http\Controllers\Api\V2\PortController;
use App\Http\Controllers\Api\V2\PortRegistryController;
use App\Http\Controllers\Api\V2\SearchController;
use App\Http\Controllers\Api\V2\ServiceController;
use App\Http\Controllers\Api\V2\SshKeyController;
use App\Http\Controllers\Api\V2\StatsController;
use App\Http\Controllers\Api\V2\TaskController;
use App\Http\Controllers\Api\V2\UserController;
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
Route::pattern('range', '\d{1,5}(-\d{1,5})?');

Route::get('/me', MeController::class)->name('me');

// Account of the key's owner (E34 batch 6 §4.2, §4.3)
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::middleware('not-readonly')->group(function () {
    Route::post('/notifications/acknowledge', [NotificationController::class, 'acknowledgeAll'])->name('notifications.acknowledge-all');
    Route::post('/notifications/{id}/acknowledge', [NotificationController::class, 'acknowledge'])->whereNumber('id')->name('notifications.acknowledge');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id')->name('notifications.destroy');
    Route::delete('/notifications', [NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');
});
Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
Route::get('/search', SearchController::class)->name('search');
Route::get('/access-key-sets', [AccessKeyController::class, 'sets'])->name('access-key-sets.index');

// Docker catalog (E34 batch 4 spec §4.3, D44)
Route::get('/docker-catalog', [DockerCatalogController::class, 'index'])->defaults('api_area', 'apps')->name('docker-catalog.index');
Route::get('/docker-catalog/{slug}', [DockerCatalogController::class, 'show'])->defaults('api_area', 'apps')->name('docker-catalog.show');

Route::get('/tasks/{id}', [TaskController::class, 'show'])->where('id', '.+')->defaults('api_area', 'backup')->name('tasks.show');
Route::get('/system/tasks', [TaskController::class, 'system'])->defaults('api_area', 'backup')->name('system.tasks.index');

Route::get('/system/docker', [DockerEngineController::class, 'show'])->defaults('api_area', 'server')->name('system.docker.show');
Route::post('/system/docker/install', [DockerEngineController::class, 'install'])->middleware('not-readonly')->defaults('api_area', 'server')->name('system.docker.install');

// Port registry of the server (E34 batch 4 spec §4.5); PORT_REGISTRY=no answers 404
Route::middleware('sys-config:PORT_REGISTRY!=no')->prefix('/system/ports')->name('system.ports.')->group(function () {
    Route::get('/', [PortRegistryController::class, 'show'])->defaults('api_area', 'server')->name('show');
    Route::middleware('not-readonly')->group(function () {
        Route::post('/allocate', [PortRegistryController::class, 'allocate'])->defaults('api_area', 'server')->name('allocate');
        Route::put('/range', [PortRegistryController::class, 'range'])->defaults('api_area', 'server')->name('range');
        Route::post('/reserved', [PortRegistryController::class, 'storeReserved'])->defaults('api_area', 'server')->name('reserved.store');
        Route::delete('/reserved/{range}', [PortRegistryController::class, 'destroyReserved'])->defaults('api_area', 'server')->name('reserved.destroy');
        Route::post('/repair', [PortRegistryController::class, 'repair'])->defaults('api_area', 'server')->name('repair');
        Route::post('/register', [PortRegistryController::class, 'register'])->defaults('api_area', 'server')->name('register');
        Route::post('/adopt', [PortRegistryController::class, 'adopt'])->defaults('api_area', 'server')->name('adopt');
        Route::post('/close-rule', [PortRegistryController::class, 'closeRule'])->defaults('api_area', 'server')->name('close-rule');
    });
});

// Services and IP addresses of the server (E34 batch 6 spec §5.1, §5.2); administrators only
Route::prefix('/system')->name('system.')->group(function () {
    $service = ['service' => '[A-Za-z0-9][\w.@-]{0,63}'];
    $ip = ['ip' => '[0-9A-Fa-f:.]{2,45}'];
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/services/status/{kind}', [ServiceController::class, 'status'])->where('kind', '[a-z]{2,4}')->name('services.status');
    Route::get('/ips', [IpController::class, 'index'])->name('ips.index');
    Route::get('/ips/{ip}', [IpController::class, 'show'])->where($ip)->name('ips.show');
    Route::get('/interfaces', [IpController::class, 'interfaces'])->name('interfaces.index');
    Route::middleware('not-readonly')->group(function () use ($service, $ip) {
        Route::post('/services/{service}/start', [ServiceController::class, 'start'])->where($service)->name('services.start');
        Route::post('/services/{service}/stop', [ServiceController::class, 'stop'])->where($service)->name('services.stop');
        Route::post('/services/{service}/restart', [ServiceController::class, 'restart'])->where($service)->name('services.restart');
        Route::post('/restart', [ServiceController::class, 'restartServer'])->name('restart');
        Route::post('/ips', [IpController::class, 'store'])->name('ips.store');
        Route::post('/ips/refresh', [IpController::class, 'refresh'])->name('ips.refresh');
        Route::patch('/ips/{ip}', [IpController::class, 'update'])->where($ip)->name('ips.update');
        Route::delete('/ips/{ip}', [IpController::class, 'destroy'])->where($ip)->name('ips.destroy');
    });
});

// Users (spec §6.1); {user} outside the principal's tree answers 404
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::post('/users', [UserController::class, 'store'])->middleware('not-readonly')->name('users.store');
Route::middleware('effective-user')->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/tasks', [TaskController::class, 'index'])->defaults('api_area', 'backup')->name('users.tasks.index');

    // Logs, statistics and SSH keys of a user (E34 batch 6 §4.1, §4.2, §4.4)
    Route::get('/users/{user}/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/users/{user}/auth-logs', [LogController::class, 'logins'])->name('auth-logs.index');
    Route::get('/users/{user}/stats', [StatsController::class, 'show'])->name('users.stats');
    Route::get('/users/{user}/ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys.index');
    Route::middleware('not-readonly')->group(function () {
        Route::delete('/users/{user}/logs', [LogController::class, 'destroy'])->name('logs.destroy');
        Route::delete('/users/{user}/auth-logs', [LogController::class, 'destroyLogins'])->name('auth-logs.destroy');
        Route::post('/users/{user}/ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
        Route::delete('/users/{user}/ssh-keys/{id}', [SshKeyController::class, 'destroy'])->where('id', '.{1,255}')->name('ssh-keys.destroy');
    });

    // Access keys and two-factor authentication of a user (E34 batch 6 §4.5, §4.6)
    Route::get('/users/{user}/access-keys', [AccessKeyController::class, 'index'])->name('access-keys.index');
    Route::get('/users/{user}/access-keys/{key}', [AccessKeyController::class, 'show'])->where('key', '[A-Za-z0-9]{20}')->name('access-keys.show');
    Route::middleware('not-readonly')->group(function () {
        Route::post('/users/{user}/access-keys', [AccessKeyController::class, 'store'])->name('access-keys.store');
        Route::delete('/users/{user}/access-keys/{key}', [AccessKeyController::class, 'destroy'])->where('key', '[A-Za-z0-9]{20}')->name('access-keys.destroy');
        Route::delete('/users/{user}/two-factor', [UserController::class, 'disableTwoFactor'])->name('users.two-factor.destroy');
    });
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

    // Node.js apps (E34 batch 4 spec §4.2)
    Route::get('/users/{user}/node-apps', [NodeAppController::class, 'index'])->name('node-apps.index');
    Route::get('/users/{user}/node-apps/detect/{domain}', [NodeAppController::class, 'detect'])->name('node-apps.detect');
    Route::get('/users/{user}/node-apps/{domain}', [NodeAppController::class, 'show'])->name('node-apps.show');
    Route::get('/users/{user}/node-apps/{domain}/env', [NodeAppController::class, 'env'])->name('node-apps.env.show');
    Route::get('/users/{user}/node-apps/{domain}/logs', [NodeAppController::class, 'logs'])->name('node-apps.logs');
    Route::middleware('not-readonly')->prefix('/users/{user}/node-apps')->name('node-apps.')->group(function () {
        Route::post('/', [NodeAppController::class, 'store'])->name('store');
        Route::patch('/{domain}', [NodeAppController::class, 'update'])->name('update');
        Route::delete('/{domain}', [NodeAppController::class, 'destroy'])->name('destroy');
        Route::post('/{domain}/start', [NodeAppController::class, 'start'])->name('start');
        Route::post('/{domain}/stop', [NodeAppController::class, 'stop'])->name('stop');
        Route::post('/{domain}/restart', [NodeAppController::class, 'restart'])->name('restart');
        Route::post('/{domain}/reload', [NodeAppController::class, 'reload'])->name('reload');
        Route::post('/{domain}/install', [NodeAppController::class, 'install'])->name('install');
        Route::post('/{domain}/build', [NodeAppController::class, 'build'])->name('build');
        Route::put('/{domain}/env', [NodeAppController::class, 'setEnv'])->name('env.update');
    });

    // Docker apps (E34 batch 4 spec §4.3)
    Route::get('/users/{user}/docker-apps', [DockerAppController::class, 'index'])->name('docker-apps.index');
    Route::get('/users/{user}/docker-apps/{name}', [DockerAppController::class, 'show'])->name('docker-apps.show');
    Route::get('/users/{user}/docker-apps/{name}/env', [DockerAppController::class, 'env'])->name('docker-apps.env.show');
    Route::get('/users/{user}/docker-apps/{name}/logs', [DockerAppController::class, 'logs'])->name('docker-apps.logs');
    Route::middleware('not-readonly')->prefix('/users/{user}/docker-apps')->name('docker-apps.')->group(function () {
        Route::post('/', [DockerAppController::class, 'store'])->name('store');
        Route::delete('/{name}', [DockerAppController::class, 'destroy'])->name('destroy');
        Route::post('/{name}/tasks', [DockerAppController::class, 'startTask'])->name('tasks');
        Route::post('/{name}/start', [DockerAppController::class, 'start'])->name('start');
        Route::post('/{name}/stop', [DockerAppController::class, 'stop'])->name('stop');
        Route::post('/{name}/restart', [DockerAppController::class, 'restart'])->name('restart');
        Route::put('/{name}/env', [DockerAppController::class, 'setEnv'])->name('env.update');
        Route::put('/{name}/limits', [DockerAppController::class, 'limits'])->name('limits');
        Route::put('/{name}/domain', [DockerAppController::class, 'domain'])->name('domain');
    });

    // Ports of a user (E34 batch 4 spec §4.5); PORT_REGISTRY=no answers 404
    Route::middleware('sys-config:PORT_REGISTRY!=no')->prefix('/users/{user}/ports')->name('ports.')->group(function () {
        Route::get('/', [PortController::class, 'index'])->name('index');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/reserve', [PortController::class, 'reserve'])->name('reserve');
            Route::post('/give', [PortController::class, 'give'])->name('give');
            Route::post('/request', [PortController::class, 'request'])->name('request');
            Route::post('/{range}/release', [PortController::class, 'release'])->name('release');
            Route::put('/{range}/public', [PortController::class, 'public'])->name('public');
            Route::put('/{range}/public-ok', [PortController::class, 'publicOk'])->name('public-ok');
            Route::post('/{range}/reclaim', [PortController::class, 'reclaim'])->name('reclaim');
            Route::delete('/{range}', [PortController::class, 'destroy'])->name('destroy');
        });
    });

    // Files of a user's home directory (E34 batch 5 spec §4); FILE_MANAGER off answers 404
    Route::middleware('files.enabled')->prefix('/users/{user}/files')->name('files.')->group(function () {
        Route::get('/', [FileController::class, 'index'])->name('index');
        Route::get('/info', [FileController::class, 'info'])->name('info');
        Route::get('/content', [FileController::class, 'read'])->name('content.show');
        Route::get('/search', [FileController::class, 'search'])->name('search');
        Route::get('/size', [FileController::class, 'size'])->name('size');
        // A download changes nothing: read-only keys may download (D51)
        Route::post('/download', [FileTransferController::class, 'download'])->name('download');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/uploads', [FileTransferController::class, 'start'])->name('uploads.store');
            // Chunks have their own limiter instead of the 60 a minute of every call (D50)
            Route::put('/uploads/{token}/{index}', [FileTransferController::class, 'chunk'])
                ->where(['token' => '[a-f0-9]{32}', 'index' => '[0-9]{1,6}'])
                ->withoutMiddleware('throttle:api-v2')->middleware('throttle:api-v2-upload')
                ->name('uploads.chunk');
            Route::post('/uploads/{token}/finish', [FileTransferController::class, 'finish'])->where('token', '[a-f0-9]{32}')->name('uploads.finish');
            Route::delete('/uploads/{token}', [FileTransferController::class, 'cancel'])->where('token', '[a-f0-9]{32}')->name('uploads.destroy');
            Route::put('/content', [FileController::class, 'save'])->name('content.update');
            Route::post('/directories', [FileController::class, 'createDirectory'])->name('directories.store');
            Route::post('/touch', [FileController::class, 'touch'])->name('touch');
            Route::post('/rename', [FileController::class, 'rename'])->name('rename');
            Route::post('/copy', [FileController::class, 'copy'])->name('copy');
            Route::post('/move', [FileController::class, 'move'])->name('move');
            Route::post('/delete', [FileController::class, 'delete'])->name('delete');
            Route::post('/chmod', [FileController::class, 'chmod'])->name('chmod');
            Route::post('/compress', [FileController::class, 'compress'])->name('compress');
            Route::post('/extract', [FileController::class, 'extract'])->name('extract');
        });
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
        str_contains($uri, '/files') => 'files',
        str_contains($uri, '/system/docker') || str_contains($uri, '/system/ports') => 'server',
        str_starts_with($uri, 'api/v2/system/') => 'server',
        str_contains($uri, '/docker-catalog') || str_contains($uri, '/docker-apps') => 'apps',
        str_contains($uri, '/ports') => 'apps',
        str_contains($uri, '/node') => 'apps',
        str_starts_with($uri, 'api/v2/users'), str_starts_with($uri, 'api/v2/packages') => 'accounts',
        in_array($uri, ['api/v2/stats', 'api/v2/search', 'api/v2/access-key-sets'], true), str_starts_with($uri, 'api/v2/notifications') => 'accounts',
        default => null,
    };
    if ($area !== null) {
        $route->defaults('api_area', $area);
    }
}
