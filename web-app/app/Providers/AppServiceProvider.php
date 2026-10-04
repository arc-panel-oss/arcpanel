<?php

namespace App\Providers;

use App\Arc\ArcCommand;
use App\Arc\ProcessArcCommand;
use App\Arc\SysConfig;
use App\Auth\LegacySession;
use App\Auth\NativeLegacySession;
use Illuminate\Support\Facades\Log;
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
    }
}
