<?php

use App\Api\ApiHeaders;
use App\Http\Controllers\Api\V2\AccessKeyController;
use App\Http\Controllers\Api\V2\AppProcessController;
use App\Http\Controllers\Api\V2\BackupController;
use App\Http\Controllers\Api\V2\BucketController;
use App\Http\Controllers\Api\V2\CronController;
use App\Http\Controllers\Api\V2\DatabaseController;
use App\Http\Controllers\Api\V2\DnsController;
use App\Http\Controllers\Api\V2\DockerAppController;
use App\Http\Controllers\Api\V2\DockerCatalogController;
use App\Http\Controllers\Api\V2\DockerEngineController;
use App\Http\Controllers\Api\V2\FileController;
use App\Http\Controllers\Api\V2\FileFetchController;
use App\Http\Controllers\Api\V2\FileTransferController;
use App\Http\Controllers\Api\V2\FirewallBanController;
use App\Http\Controllers\Api\V2\FirewallIpsetController;
use App\Http\Controllers\Api\V2\FirewallRuleController;
use App\Http\Controllers\Api\V2\FirewallStatusController;
use App\Http\Controllers\Api\V2\GraphController;
use App\Http\Controllers\Api\V2\IpController;
use App\Http\Controllers\Api\V2\LogController;
use App\Http\Controllers\Api\V2\MailAccountController;
use App\Http\Controllers\Api\V2\MailDomainController;
use App\Http\Controllers\Api\V2\MalwareController;
use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\NodeAppController;
use App\Http\Controllers\Api\V2\NodeController;
use App\Http\Controllers\Api\V2\NotificationController;
use App\Http\Controllers\Api\V2\ObjectStorageController;
use App\Http\Controllers\Api\V2\PackageController;
use App\Http\Controllers\Api\V2\PhpController;
use App\Http\Controllers\Api\V2\PhpProfileController;
use App\Http\Controllers\Api\V2\PortController;
use App\Http\Controllers\Api\V2\PortRegistryController;
use App\Http\Controllers\Api\V2\RequestController;
use App\Http\Controllers\Api\V2\S3KeyController;
use App\Http\Controllers\Api\V2\SearchController;
use App\Http\Controllers\Api\V2\SecretController;
use App\Http\Controllers\Api\V2\ServerSettingsController;
use App\Http\Controllers\Api\V2\ServiceController;
use App\Http\Controllers\Api\V2\SshKeyController;
use App\Http\Controllers\Api\V2\StatsController;
use App\Http\Controllers\Api\V2\SystemPackageController;
use App\Http\Controllers\Api\V2\TaskController;
use App\Http\Controllers\Api\V2\UpdateCenterController;
use App\Http\Controllers\Api\V2\UpdateController;
use App\Http\Controllers\Api\V2\UserController;
use App\Http\Controllers\Api\V2\WebDomainController;
use App\Http\Controllers\Api\V2\WebDomainPhpController;
use App\Services\Server\UpdateCenter;
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
// The request journal (E43 B4): no area, every key may ask; the journal scopes it
Route::get('/requests/{id}', [RequestController::class, 'show'])->where('id', ApiHeaders::REQUEST_ID_PATTERN)->name('requests.show');

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

// Cross-user lists of the key's scope, for administrators and resellers (E43 B2)
Route::get('/web-domains', [WebDomainController::class, 'indexAll'])->name('web-domains.all');
Route::get('/databases', [DatabaseController::class, 'indexAll'])->name('databases.all');
Route::get('/cron-jobs', [CronController::class, 'indexAll'])->name('cron-jobs.all');
Route::get('/node-apps', [NodeAppController::class, 'indexAll'])->name('node-apps.all');
Route::get('/backups', [BackupController::class, 'indexAll'])->name('backups.all');
Route::get('/backup-runs', [BackupController::class, 'runsAll'])->name('backup-runs.all');
Route::get('/buckets', [BucketController::class, 'indexAll'])->middleware('sys-config:OBJECT_STORAGE_SYSTEM=silo')->name('buckets.all');

// Malware scan summary of the principal's tree (E25 batch 1 spec §9)
Route::get('/malware', [MalwareController::class, 'summary'])->middleware('sys-config:MALWARE_SCAN=yes')->name('malware.summary');
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
    Route::get('/services/{service}/logs', [ServiceController::class, 'logs'])->where($service)->name('services.logs');
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

// Updates, system packages and graphs of the server (E34 batch 6 spec §5.4–§5.6, D65); administrators only
Route::prefix('/system')->name('system.')->group(function () {
    $slug = ['slug' => '[a-z0-9][a-z0-9-]{0,63}'];
    Route::get('/updates', [UpdateController::class, 'index'])->name('updates.index');
    Route::get('/packages', [SystemPackageController::class, 'index'])->name('packages.index');
    Route::get('/graphs', [GraphController::class, 'index'])->name('graphs.index');
    Route::get('/graphs/{graph}', [GraphController::class, 'show'])->where('graph', '[\w.-]{1,64}')->name('graphs.show');
    Route::middleware('not-readonly')->group(function () use ($slug) {
        Route::post('/updates', [UpdateController::class, 'run'])->name('updates.run');
        Route::put('/updates/settings', [UpdateController::class, 'settings'])->name('updates.settings');
        Route::post('/packages/{slug}', [SystemPackageController::class, 'install'])->where($slug)->name('packages.install');
        Route::delete('/packages/{slug}', [SystemPackageController::class, 'remove'])->where($slug)->name('packages.remove');
    });
});

// The Update Center of the panel (E33 phase 1 spec §9); administrators only
Route::prefix('/system/updates')->name('system.updates.')->group(function () {
    $release = ['version' => UpdateCenter::VERSION_PATTERN];
    Route::get('/releases', [UpdateCenterController::class, 'releases'])->defaults('api_area', 'server')->name('releases.index');
    Route::get('/releases/{version}/preflight', [UpdateCenterController::class, 'preflight'])->where($release)->defaults('api_area', 'server')->name('releases.preflight');
    Route::get('/policy', [UpdateCenterController::class, 'policy'])->defaults('api_area', 'server')->name('policy.show');
    Route::get('/history', [UpdateCenterController::class, 'history'])->defaults('api_area', 'server')->name('history.index');
    Route::middleware('not-readonly')->group(function () {
        Route::patch('/policy', [UpdateCenterController::class, 'setPolicy'])->defaults('api_area', 'server')->name('policy.update');
        Route::post('/resume', [UpdateCenterController::class, 'resume'])->defaults('api_area', 'server')->name('resume');
        Route::post('/history/{id}/rollback', [UpdateCenterController::class, 'rollback'])->whereNumber('id')->defaults('api_area', 'server')->name('history.rollback');
    });
});

// PHP Manager (E41 spec 2026-10-12-001 §9): versions, extensions, php.ini and
// PHP-FPM profiles; administrators only. {php} is a version such as 8.4.
Route::prefix('/system/php')->name('system.php.')->group(function () {
    $php = ['php' => '\d{1,2}\.\d{1,2}'];
    $sapi = ['sapi' => 'fpm|cli'];
    $name = ['name' => '[a-z0-9_]{1,32}'];
    $profile = ['profile' => '[a-z0-9][a-z0-9-]{0,31}'];
    Route::get('/versions', [PhpController::class, 'index'])->name('versions.index');
    Route::get('/versions/{php}', [PhpController::class, 'show'])->where($php)->name('versions.show');
    Route::get('/versions/{php}/extensions', [PhpController::class, 'extensions'])->where($php)->name('extensions.index');
    Route::get('/versions/{php}/ini/{sapi}', [PhpController::class, 'ini'])->where($php + $sapi)->name('ini.show');
    Route::get('/versions/{php}/ini/{sapi}/backups', [PhpController::class, 'backups'])->where($php + $sapi)->name('ini.backups.index');
    Route::get('/profiles', [PhpProfileController::class, 'index'])->name('profiles.index');
    Route::get('/profiles/{profile}', [PhpProfileController::class, 'show'])->where($profile)->name('profiles.show');
    Route::middleware('not-readonly')->group(function () use ($php, $sapi, $name, $profile) {
        Route::post('/versions/{php}', [PhpController::class, 'install'])->where($php)->name('versions.install');
        Route::delete('/versions/{php}', [PhpController::class, 'remove'])->where($php)->name('versions.remove');
        Route::put('/versions/{php}/extensions/{name}', [PhpController::class, 'setExtension'])->where($php + $name)->name('extensions.update');
        Route::delete('/versions/{php}/extensions/{name}', [PhpController::class, 'removeExtension'])->where($php + $name)->name('extensions.destroy');
        Route::put('/versions/{php}/ini/{sapi}', [PhpController::class, 'replaceIni'])->where($php + $sapi)->name('ini.replace');
        Route::patch('/versions/{php}/ini/{sapi}', [PhpController::class, 'updateIni'])->where($php + $sapi)->name('ini.update');
        Route::post('/versions/{php}/ini/{sapi}/backups/{backup}/restore', [PhpController::class, 'restore'])
            ->where($php + $sapi + ['backup' => '\d{8}-\d{6}(-\d+)?'])->name('ini.backups.restore');
        Route::post('/profiles', [PhpProfileController::class, 'store'])->name('profiles.store');
        Route::put('/profiles/{profile}', [PhpProfileController::class, 'update'])->where($profile)->name('profiles.update');
        Route::delete('/profiles/{profile}', [PhpProfileController::class, 'destroy'])->where($profile)->name('profiles.destroy');
    });
});

// Server settings (E34 batch 6 spec §5.7, D58); administrators only
Route::prefix('/system/settings')->name('system.settings.')->group(function () {
    Route::get('/', [ServerSettingsController::class, 'show'])->name('show');
    Route::middleware('not-readonly')->group(function () {
        Route::put('/web/php', [ServerSettingsController::class, 'php'])->name('php');
        Route::put('/plugins/{plugin}', [ServerSettingsController::class, 'plugin'])->where('plugin', '[a-z][a-z-]{0,31}')->name('plugin');
        Route::put('/databases/hosts/{type}/{host}/password', [ServerSettingsController::class, 'databaseHostPassword'])
            ->where(['type' => '[a-z]{1,16}', 'host' => '[A-Za-z0-9][A-Za-z0-9.:_-]{0,252}'])
            ->name('database-host-password');
        Route::patch('/{tab}', [ServerSettingsController::class, 'update'])
            ->where('tab', 'general|web|dns|mail|databases|backups|ssl|security|plugins|white-label')
            ->name('update');
    });
});

// Object storage service of the server (E44 spec §10)
Route::prefix('/system/object-storage')->name('system.object-storage.')->group(function () {
    Route::get('/', [ObjectStorageController::class, 'show'])->name('show');
    Route::middleware('not-readonly')->group(function () {
        Route::post('/', [ObjectStorageController::class, 'install'])->name('install');
        Route::put('/endpoint', [ObjectStorageController::class, 'endpoint'])->name('endpoint');
    });
});

// Firewall of the server (E34 batch 6 spec §5.3, D59); FIREWALL_SYSTEM off answers 404, the ban list also needs FIREWALL_EXTENSION
Route::middleware('sys-config:FIREWALL_SYSTEM')->prefix('/system/firewall')->name('system.firewall.')->group(function () {
    $rule = ['rule' => '[0-9]{1,9}'];
    Route::get('/status', [FirewallStatusController::class, 'show'])->name('status');
    Route::get('/rules', [FirewallRuleController::class, 'index'])->name('rules.index');
    Route::get('/rules/{rule}', [FirewallRuleController::class, 'show'])->where($rule)->name('rules.show');
    Route::get('/ipsets', [FirewallIpsetController::class, 'index'])->name('ipsets.index');
    Route::middleware('not-readonly')->group(function () use ($rule) {
        Route::post('/rules', [FirewallRuleController::class, 'store'])->name('rules.store');
        Route::patch('/rules/{rule}', [FirewallRuleController::class, 'update'])->where($rule)->name('rules.update');
        Route::delete('/rules/{rule}', [FirewallRuleController::class, 'destroy'])->where($rule)->name('rules.destroy');
        Route::post('/rules/{rule}/move', [FirewallRuleController::class, 'move'])->where($rule)->name('rules.move');
        Route::post('/rules/{rule}/suspend', [FirewallRuleController::class, 'suspend'])->where($rule)->name('rules.suspend');
        Route::post('/rules/{rule}/unsuspend', [FirewallRuleController::class, 'unsuspend'])->where($rule)->name('rules.unsuspend');
        Route::post('/ipsets', [FirewallIpsetController::class, 'store'])->name('ipsets.store');
        Route::delete('/ipsets/{name}', [FirewallIpsetController::class, 'destroy'])->where('name', '[\w.-]{1,255}')->name('ipsets.destroy');
    });
    Route::middleware('sys-config:FIREWALL_EXTENSION')->group(function () {
        Route::get('/bans', [FirewallBanController::class, 'index'])->name('bans.index');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/bans', [FirewallBanController::class, 'store'])->name('bans.store');
            Route::delete('/bans/{chain}/{ip}', [FirewallBanController::class, 'destroy'])
                ->where(['chain' => 'SSH|WEB|FTP|DNS|MAIL|DB|ARCPANEL|RECIDIVE', 'ip' => '[0-9A-Fa-f:.\/]{2,64}'])
                ->name('bans.destroy');
        });
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

    // Secret store of a user (E38 spec §8); {name} is the secret name, with or
    // without its leading slash (the SDK sends the name as listed, encoded)
    $secret = '[A-Za-z0-9_.\/-]{1,128}';
    Route::get('/users/{user}/secrets', [SecretController::class, 'index'])->name('secrets.index');
    Route::get('/users/{user}/secrets/{name}', [SecretController::class, 'show'])->where('name', $secret)->name('secrets.show');
    Route::middleware('not-readonly')->group(function () use ($secret) {
        Route::post('/users/{user}/secrets', [SecretController::class, 'store'])->name('secrets.store');
        Route::put('/users/{user}/secrets/{name}/value', [SecretController::class, 'value'])->where('name', $secret)->name('secrets.value');
        Route::post('/users/{user}/secrets/{name}/rollback', [SecretController::class, 'rollback'])->where('name', $secret)->name('secrets.rollback');
        Route::patch('/users/{user}/secrets/{name}', [SecretController::class, 'update'])->where('name', $secret)->name('secrets.update');
        Route::delete('/users/{user}/secrets/{name}', [SecretController::class, 'destroy'])->where('name', $secret)->name('secrets.destroy');
    });

    // Object storage of a user (E44 spec §10); 404 without Silo on the server
    Route::middleware('sys-config:OBJECT_STORAGE_SYSTEM=silo')->group(function () {
        $bucket = ['bucket' => '[a-z0-9][a-z0-9-]{1,61}[a-z0-9]'];
        $key = ['key' => '[A-Za-z0-9]{3,64}'];
        Route::get('/users/{user}/buckets', [BucketController::class, 'index'])->name('buckets.index');
        Route::get('/users/{user}/buckets/{bucket}', [BucketController::class, 'show'])->where($bucket)->name('buckets.show');
        Route::get('/users/{user}/s3-keys', [S3KeyController::class, 'index'])->name('s3-keys.index');
        Route::middleware('not-readonly')->group(function () use ($bucket, $key) {
            Route::post('/users/{user}/buckets', [BucketController::class, 'store'])->name('buckets.store');
            Route::patch('/users/{user}/buckets/{bucket}', [BucketController::class, 'update'])->where($bucket)->name('buckets.update');
            Route::delete('/users/{user}/buckets/{bucket}', [BucketController::class, 'destroy'])->where($bucket)->name('buckets.destroy');
            Route::post('/users/{user}/s3-keys', [S3KeyController::class, 'store'])->name('s3-keys.store');
            Route::delete('/users/{user}/s3-keys/{key}', [S3KeyController::class, 'destroy'])->where($key)->name('s3-keys.destroy');
        });
    });

    // Malware scan of a user (E25 batch 1 spec §9); 404 while the plugin is off
    Route::middleware('sys-config:MALWARE_SCAN=yes')->prefix('/users/{user}/malware')->name('malware.')->group(function () {
        $finding = ['finding' => '[a-f0-9]{16}'];
        $quarantined = ['id' => '[0-9]{14}'];
        Route::get('/findings', [MalwareController::class, 'findings'])->name('findings.index');
        Route::get('/scans', [MalwareController::class, 'scans'])->name('scans.index');
        Route::get('/quarantine', [MalwareController::class, 'quarantined'])->name('quarantine.index');
        Route::get('/ignores', [MalwareController::class, 'ignores'])->name('ignores.index');
        Route::middleware('not-readonly')->group(function () use ($finding, $quarantined) {
            Route::post('/scans', [MalwareController::class, 'start'])->name('scans.store');
            Route::post('/findings/{finding}/quarantine', [MalwareController::class, 'quarantine'])->where($finding)->name('findings.quarantine');
            Route::post('/findings/{finding}/ignore', [MalwareController::class, 'ignore'])->where($finding)->name('findings.ignore');
            Route::post('/quarantine/{id}/restore', [MalwareController::class, 'restore'])->where($quarantined)->name('quarantine.restore');
            Route::delete('/quarantine/{id}', [MalwareController::class, 'destroyQuarantined'])->where($quarantined)->name('quarantine.destroy');
            Route::delete('/ignores/{sha256}', [MalwareController::class, 'destroyIgnore'])->where('sha256', '[a-f0-9]{64}')->name('ignores.destroy');
        });
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
    Route::get('/users/{user}/web-domains/{domain}/logs', [WebDomainController::class, 'logs'])->name('web-domains.logs');
    // PHP version and PHP-FPM profile (E41 §9)
    Route::get('/users/{user}/web-domains/{domain}/php', [WebDomainPhpController::class, 'show'])->name('web-domains.php.show');
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
        Route::put('/{domain}/php', [WebDomainPhpController::class, 'update'])->middleware('throttle:php-switch')->name('php.update');
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
    Route::get('/users/{user}/backup-runs', [BackupController::class, 'runs'])->name('backup-runs.index');
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

    // App processes (E42 spec §8)
    Route::get('/users/{user}/app-processes', [AppProcessController::class, 'index'])->name('app-processes.index');
    Route::get('/users/{user}/app-process-presets', [AppProcessController::class, 'presets'])->name('app-process-presets.index');
    Route::get('/users/{user}/app-process-task', [AppProcessController::class, 'task'])->name('app-process-task.show');
    Route::get('/users/{user}/app-processes/{domain}/{name}', [AppProcessController::class, 'show'])->name('app-processes.show');
    Route::get('/users/{user}/app-processes/{domain}/{name}/env', [AppProcessController::class, 'env'])->name('app-processes.env.show');
    Route::get('/users/{user}/app-processes/{domain}/{name}/logs', [AppProcessController::class, 'logs'])->name('app-processes.logs');
    Route::middleware('not-readonly')->prefix('/users/{user}/app-processes')->name('app-processes.')->group(function () {
        Route::post('/', [AppProcessController::class, 'store'])->name('store');
        Route::patch('/{domain}/{name}', [AppProcessController::class, 'update'])->name('update');
        Route::delete('/{domain}/{name}', [AppProcessController::class, 'destroy'])->name('destroy');
        Route::post('/{domain}/{name}/start', [AppProcessController::class, 'start'])->name('start');
        Route::post('/{domain}/{name}/stop', [AppProcessController::class, 'stop'])->name('stop');
        Route::post('/{domain}/{name}/restart', [AppProcessController::class, 'restart'])->name('restart');
        Route::post('/{domain}/{name}/reload', [AppProcessController::class, 'reload'])->name('reload');
        Route::put('/{domain}/{name}/env', [AppProcessController::class, 'setEnv'])->name('env.update');
        Route::post('/{domain}/{name}/tasks', [AppProcessController::class, 'startTask'])->name('tasks.store');
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
        // Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §8); FILE_FETCH off answers 404
        Route::middleware('files.fetch')->group(function () {
            Route::get('/fetches', [FileFetchController::class, 'index'])->name('fetches.index');
            Route::get('/fetches/{id}', [FileFetchController::class, 'show'])->where('id', '[0-9]{10,14}')->name('fetches.show');
            Route::middleware('not-readonly')->group(function () {
                Route::post('/fetches', [FileFetchController::class, 'store'])->name('fetches.store');
                Route::delete('/fetches/{id}', [FileFetchController::class, 'destroy'])->where('id', '[0-9]{10,14}')->name('fetches.destroy');
            });
        });
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
        // The settings host path names a database host, not the db area
        str_starts_with($uri, 'api/v2/system/settings/') => 'server',
        // App processes and their tasks, before the /tasks match
        str_contains($uri, '/app-process') => 'apps',
        str_contains($uri, '/web-domains') => 'web',
        str_contains($uri, '/secrets') => 'secrets',
        str_contains($uri, '/buckets'), str_contains($uri, '/s3-keys') => 'storage',
        str_contains($uri, '/malware') => 'security',
        str_contains($uri, '/dns-zones') => 'dns',
        str_contains($uri, '/mail-domains') => 'mail',
        str_contains($uri, '/databases') => 'db',
        str_contains($uri, '/cron-jobs'), str_contains($uri, '/cron-reports') => 'cron',
        str_contains($uri, '/backups'), str_contains($uri, '/backup-runs'), str_contains($uri, '/snapshots'), str_contains($uri, '/backup-exclusions') => 'backup',
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
