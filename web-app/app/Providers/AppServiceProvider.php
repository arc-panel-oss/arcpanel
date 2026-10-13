<?php

namespace App\Providers;

use App\Api\KeyPermissions;
use App\Arc\ArcCommand;
use App\Arc\ProcessArcCommand;
use App\Arc\SysConfig;
use App\Auth\ClientIp;
use App\Auth\LegacySession;
use App\Auth\NativeLegacySession;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Services\Files\FileStaging;
use App\Services\Mail\AccountMailer;
use App\Services\Mail\CredentialsMailer;
use App\Services\Mail\HostingCredentialsMailer;
use App\Services\Mail\PanelCredentialsMailer;
use App\Services\Mail\PasswordResetMailer;
use App\Services\Node\NodeAccess;
use App\Services\Server\SystemPackages;
use App\Tenancy\TenancyDirectory;
use App\View\AssetManifest;
use App\View\PageTabsComposer;
use App\View\ShellComposer;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArcCommand::class, fn ($app) => new ProcessArcCommand(
            allowList: (array) config('arc-commands', []),
            binPath: (string) config('arc.bin_path'),
            sudo: (string) config('arc.sudo'),
            installDir: (string) config('arc.install_dir'),
            secretDir: (string) config('arc.secret_dir'),
            defaultTimeout: (int) config('arc.command_timeout'),
            log: Log::channel('arc'),
        ));

        $this->app->singleton(SysConfig::class, fn ($app) => new SysConfig(
            $app->make(ArcCommand::class),
            $app->make('cache.store'),
            (int) config('arc.sys_config_ttl'),
        ));

        $this->app->scoped(LegacySession::class, fn ($app) => new NativeLegacySession(
            savePath: (string) config('arc.sessions_path'),
            name: (string) ini_get('session.name'),
            cookies: $app->make('request')->cookies->all(),
        ));

        // Owner tree, read once per request (E11)
        $this->app->scoped(TenancyDirectory::class, fn ($app) => new TenancyDirectory($app->make(ArcCommand::class)));

        // Package key NODE per package, read once per request (E13)
        $this->app->scoped(NodeAccess::class);

        // System package catalog, read once per request (E5)
        $this->app->scoped(SystemPackages::class);

        // File manager staging tree (E3)
        $this->app->bind(FileStaging::class, fn () => FileStaging::fromConfig());

        // The Principal is bound per request by ResolvePrincipal.
        $this->app->bind(OwnerScope::class, fn ($app) => new OwnerScope($app->make(Principal::class), $app->make(TenancyDirectory::class)));

        $this->app->singleton(AssetManifest::class, fn () => new AssetManifest(public_path('build/manifest.json')));

        $this->app->bind(CredentialsMailer::class, fn ($app) => new PanelCredentialsMailer(
            $app->make(SysConfig::class),
            (string) config('arc.install_dir'),
            Log::channel('arc'),
        ));
        $this->app->bind(AccountMailer::class, fn ($app) => $app->make(CredentialsMailer::class));
        $this->app->bind(HostingCredentialsMailer::class, fn ($app) => $app->make(CredentialsMailer::class));
        $this->app->bind(PasswordResetMailer::class, fn ($app) => $app->make(CredentialsMailer::class));

        // OpenAPI export of API v2 (E34): Scramble is a dev dependency
        if ($this->app->runningInConsole() && class_exists(Scramble::class)) {
            $this->app->register(ApiDocsServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // @apAsset('app.js'): hashed URL from public/build/manifest.json
        Blade::directive('apAsset', fn (string $name) => "<?php echo e(app(\App\View\AssetManifest::class)->url({$name})); ?>");

        View::composer('components.ap.shell', ShellComposer::class);
        View::composer('components.ap.page', PageTabsComposer::class);

        // API v2: 60 requests a minute per access key (E34 spec D16)
        RateLimiter::for('api-v2', fn (Request $request) => Limit::perMinute(60)->by('api-v2:'.(app()->bound(KeyPermissions::class)
            ? app(KeyPermissions::class)->keyId
            : ClientIp::of($request))));
        // Upload chunks: 600 a minute per access key instead (E34 batch 5 D50)
        RateLimiter::for('api-v2-upload', fn (Request $request) => Limit::perMinute(600)->by('api-v2-upload:'.(app()->bound(KeyPermissions::class)
            ? app(KeyPermissions::class)->keyId
            : ClientIp::of($request))));

        // resources/views/components/ap/NAME.blade.php is used as <x-ap-NAME> (docs/design/05 §5).
        foreach (glob(resource_path('views/components/ap/*.blade.php')) ?: [] as $file) {
            $name = basename($file, '.blade.php');
            Blade::component('components.ap.'.$name, 'ap-'.$name);
        }
    }
}
