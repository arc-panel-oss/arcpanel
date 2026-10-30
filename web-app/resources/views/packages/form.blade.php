{{-- Add or edit a package (web/add/package, web/edit/package) with the reseller settings of E11 spec §8. --}}
@php
    $isNew = $record === null;
    $title = $isNew ? __('Add Package') : $name;
    $limits = [
        'WEB_DOMAINS' => [__('Web Domains'), null],
        'WEB_ALIASES' => [__('Web Aliases'), __('per domain')],
        'DNS_DOMAINS' => [__('DNS Zones'), null],
        'DNS_RECORDS' => [__('DNS Records'), __('per zone')],
        'MAIL_DOMAINS' => [__('Mail Domains'), null],
        'MAIL_ACCOUNTS' => [__('Mail Accounts'), null],
        'DATABASES' => [__('Databases'), null],
        'CRON_JOBS' => [__('Cron Jobs'), null],
        'DISK_QUOTA' => [__('Quota'), 'MB'],
        'BANDWIDTH' => [__('Bandwidth'), 'MB'],
    ];
    $own = fn (string $key) => isset($ownLimits[$key]) ? sprintf(__('Your plan: %s'), $ownLimits[$key]) : null;
    $options = fn (array $list) => array_combine($list, $list);
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Packages'), 'url' => route('packages.index')], ['label' => $title]]">
    <x-ap-form :action="$isNew ? route('packages.store') : route('packages.update', ['package' => $record->name])" :focus-first="$isNew">
        <x-ap-card :title="__('Package')" icon="package">
            <div class="ap-form-grid">
                @if ($isNew || $canRename)
                    <x-ap-input name="name" :label="__('Package Name')" :value="$name" required mono />
                @else
                    <input type="hidden" name="name" value="{{ $name }}">
                    <x-ap-input name="name_shown" :label="__('Package Name')" :value="$name" mono disabled />
                @endif
                <x-ap-select name="web_template" :label="__('Web Template')" :options="$options($templates['web'])" :value="$values['WEB_TEMPLATE']" />
                @if ($templates['backend'] !== [])
                    <x-ap-select name="backend_template" :label="__('Backend Template')" :options="$options($templates['backend'])" :value="$values['BACKEND_TEMPLATE']" />
                @endif
                @if ($templates['proxy'] !== [])
                    <x-ap-select name="proxy_template" :label="__('Proxy Template')" :options="$options($templates['proxy'])" :value="$values['PROXY_TEMPLATE']" />
                @endif
                <x-ap-select name="dns_template" :label="__('DNS Template')" :options="$options($templates['dns'])" :value="$values['DNS_TEMPLATE']" />
                <x-ap-select name="shell" :label="__('SSH Access')" :options="$options($templates['shells'])" :value="$values['SHELL']" />
            </div>
            {{-- Git Deploy of the web domains (E20 D13); the key is yes when missing --}}
            <input type="hidden" name="git_deploy_group" value="1">
            @if ($ownDeploy !== null && $ownDeploy['GIT_DEPLOY'] !== 'yes')
                <p class="muted">{{ __('Git Deploy is not included in your plan.') }}</p>
            @endif
            <div class="ap-settings">
                <x-ap-switch name="git_deploy" :label="__('Git Deploy')" :description="__('Users of this package can deploy their web domains from Git or from their CI.')"
                    :checked="\App\Services\Packages\PackageService::gitDeploy($values) === 'yes'" :disabled="$ownDeploy !== null && $ownDeploy['GIT_DEPLOY'] !== 'yes'" />
            </div>
        </x-ap-card>

        <x-ap-card :title="__('Limits')" icon="sliders">
            <div class="ap-form-grid">
                @foreach ($limits as $key => [$label, $suffix])
                    <x-ap-unlimited-input :name="strtolower($key)" :label="$label" :value="$values[$key]" :suffix="$suffix" :hint="$own($key)" />
                @endforeach
                <x-ap-input name="rate_limit" type="number" :label="__('Mail rate limit')" :value="$values['RATE_LIMIT']" suffix="/h" :hint="$own('RATE_LIMIT')" required />
                <x-ap-input name="backups" type="number" :label="__('Backups')" :value="$values['BACKUPS']" :hint="$own('BACKUPS')" required />
                <x-ap-select name="backup_frequency" :label="__('Backup frequency at most')" :value="$values['BACKUP_FREQUENCY'] ?? 'daily'"
                    :options="['hourly' => __('Every hour'), 'daily' => __('Every day'), 'weekly' => __('Every week')]"
                    :hint="$ownStorage !== null ? sprintf(__('Your plan: %s'), $ownStorage['BACKUP_FREQUENCY']) : __('Scheduled backup jobs skip an account backed up more recently than this.')" />
                <x-ap-unlimited-input name="ports" :label="__('Ports')" :value="$values['PORTS'] ?? '0'"
                    :hint="$ownPorts !== null ? sprintf(__('Your plan: %s'), $ownPorts) : __('Allocated to each new user of this package.')" />
            </div>
            <div class="ap-settings">
                <x-ap-switch name="backups_incremental" :label="__('Incremental backups')" :checked="$values['BACKUPS_INCREMENTAL'] === 'yes'" />
            </div>
            @if ($resources)
                <div class="ap-form-grid">
                    <x-ap-input name="cpu_quota" :label="__('CPU quota')" :value="$values['CPU_QUOTA']" mono :hint="__('unlimited or a percentage, e.g. 50%')" />
                    <x-ap-input name="cpu_quota_period" :label="__('CPU quota period')" :value="$values['CPU_QUOTA_PERIOD']" mono :hint="__('unlimited or a duration, e.g. 100ms')" />
                    <x-ap-input name="memory_limit" :label="__('Memory limit')" :value="$values['MEMORY_LIMIT']" mono :hint="__('unlimited or a size, e.g. 512M')" />
                    <x-ap-input name="swap_limit" :label="__('Swap limit')" :value="$values['SWAP_LIMIT']" mono />
                </div>
            @endif
            <x-ap-textarea name="nameservers" :label="__('Name servers')" :value="str_replace(',', PHP_EOL, $values['NS'])" rows="3" mono
                :hint="__('Two to eight, one per line.')" />
        </x-ap-card>

        <x-ap-card :title="__('Node.js')" icon="hexagon">
            @if ($ownNode !== null && $ownNode['NODE'] !== 'yes')
                <p class="muted">{{ __('Node.js is not included in your plan.') }}</p>
            @endif
            <div class="ap-settings">
                <x-ap-switch name="node" :label="__('Node.js')" :description="__('Users of this package may install Node.js versions and global npm packages.')"
                    :checked="$values['NODE'] === 'yes'" :disabled="$ownNode !== null && $ownNode['NODE'] !== 'yes'" />
            </div>
            <div class="ap-form-grid">
                <x-ap-unlimited-input name="node_versions" :label="__('Node.js versions')" :value="$values['NODE_VERSIONS']"
                    :hint="$ownNode !== null ? sprintf(__('Your plan: %s'), $ownNode['NODE_VERSIONS']) : __('Runtimes installed at the same time.')" />
                <x-ap-unlimited-input name="node_apps" :label="__('Node.js apps')" :value="$values['NODE_APPS'] ?? '3'"
                    :hint="$ownNode !== null ? sprintf(__('Your plan: %s'), $ownNode['NODE_APPS']) : __('Apps bound to web domains, run by pm2.')" />
                <x-ap-input name="node_allowed" :label="__('Allowed majors')" :value="$values['NODE_ALLOWED']" mono
                    :hint="$ownNode !== null ? sprintf(__('Your plan: %s'), $ownNode['NODE_ALLOWED']) : __('lts for the supported LTS lines, * for every version, or majors such as 22,24.')" />
            </div>
        </x-ap-card>

        <x-ap-card :title="__('Docker apps')" icon="container">
            @if ($ownDocker !== null && $ownDocker['DOCKER'] !== 'yes')
                <p class="muted">{{ __('Docker apps are not included in your plan.') }}</p>
            @endif
            <div class="ap-settings">
                <x-ap-switch name="docker" :label="__('Docker apps')" :description="__('Users of this package may install apps of the Docker app store on their domains.')"
                    :checked="($values['DOCKER'] ?? 'no') === 'yes'" :disabled="$ownDocker !== null && $ownDocker['DOCKER'] !== 'yes'" />
            </div>
            <div class="ap-form-grid">
                <x-ap-unlimited-input name="docker_apps" :label="__('Docker apps')" :value="$values['DOCKER_APPS'] ?? '0'"
                    :hint="$ownDocker !== null ? sprintf(__('Your plan: %s'), $ownDocker['DOCKER_APPS']) : __('Apps installed at the same time.')" />
                <x-ap-input name="docker_cpus" :label="__('CPUs per app')" :value="$values['DOCKER_CPUS'] ?? 'unlimited'" mono
                    :hint="$ownDocker !== null ? sprintf(__('Your plan: %s'), $ownDocker['DOCKER_CPUS']) : __('unlimited or a number such as 0.5 or 2')" />
                <x-ap-input name="docker_memory" :label="__('Memory per app')" :value="$values['DOCKER_MEMORY'] ?? 'unlimited'" mono
                    :hint="$ownDocker !== null ? sprintf(__('Your plan: %s'), $ownDocker['DOCKER_MEMORY']) : __('unlimited or a size, e.g. 512M; within the memory limit')" />
            </div>
        </x-ap-card>

        <x-ap-card :title="__('App processes')" icon="terminal">
            @if ($ownProcesses !== null && $ownProcesses['APP_PROCESSES'] !== 'yes')
                <p class="muted">{{ __('App processes are not included in your plan.') }}</p>
            @endif
            <div class="ap-settings">
                <x-ap-switch name="app_processes" :label="__('App processes')" :description="__('Users of this package may run long-running commands of their domains, such as Laravel Octane or queue workers, as their own user.')"
                    :checked="($values['APP_PROCESSES'] ?? 'no') === 'yes'" :disabled="$ownProcesses !== null && $ownProcesses['APP_PROCESSES'] !== 'yes'" />
            </div>
            <div class="ap-form-grid">
                <x-ap-unlimited-input name="app_processes_max" :label="__('App processes')" :value="$values['APP_PROCESSES_MAX'] ?? '3'"
                    :hint="$ownProcesses !== null ? sprintf(__('Your plan: %s'), $ownProcesses['APP_PROCESSES_MAX']) : __('Web processes and workers together.')" />
                <x-ap-input name="app_processes_cpus" :label="__('CPUs per process')" :value="$values['APP_PROCESSES_CPUS'] ?? '1'" mono
                    :hint="$ownProcesses !== null ? sprintf(__('Your plan: %s'), $ownProcesses['APP_PROCESSES_CPUS']) : __('unlimited or a number such as 0.5 or 2')" />
                <x-ap-input name="app_processes_memory" :label="__('Memory per process')" :value="$values['APP_PROCESSES_MEMORY'] ?? 'unlimited'" mono
                    :hint="$ownProcesses !== null ? sprintf(__('Your plan: %s'), $ownProcesses['APP_PROCESSES_MEMORY']) : __('unlimited or a size, e.g. 512M; within the memory limit')" />
            </div>
        </x-ap-card>

        {{-- Object storage (E44 spec §6) --}}
        <x-ap-card :title="__('Object storage')" icon="archive">
            @if ($ownStorage !== null && $ownStorage['OBJECT_STORAGE'] !== 'yes')
                <p class="muted">{{ __('Object storage is not included in your plan.') }}</p>
            @endif
            <div class="ap-settings">
                <x-ap-switch name="object_storage" :label="__('Object storage')" :description="__('Users of this package may create S3-compatible buckets and S3 keys on this server.')"
                    :checked="($values['OBJECT_STORAGE'] ?? 'no') === 'yes'" :disabled="$ownStorage !== null && $ownStorage['OBJECT_STORAGE'] !== 'yes'" />
                <x-ap-switch name="object_storage_backup" :label="__('Back up bucket contents')" :description="__('User backups include the objects of every bucket; they can make backups much larger.')"
                    :checked="($values['OBJECT_STORAGE_BACKUP'] ?? 'no') === 'yes'" :disabled="$ownStorage !== null && $ownStorage['OBJECT_STORAGE_BACKUP'] !== 'yes'" />
            </div>
            <div class="ap-form-grid">
                <x-ap-unlimited-input name="object_storage_buckets" :label="__('Buckets')" :value="$values['OBJECT_STORAGE_BUCKETS'] ?? '3'"
                    :hint="$ownStorage !== null ? sprintf(__('Your plan: %s'), $ownStorage['OBJECT_STORAGE_BUCKETS']) : __('Buckets per user.')" />
                <x-ap-input name="object_storage_quota" :label="__('Storage quota')" :value="$values['OBJECT_STORAGE_QUOTA'] ?? '1G'" mono
                    :hint="$ownStorage !== null ? sprintf(__('Your plan: %s'), $ownStorage['OBJECT_STORAGE_QUOTA']) : __('The quotas of all buckets of a user together: MB, or a size such as 500M, 10G or 1T, or unlimited.')" />
            </div>
        </x-ap-card>

        @if ($php !== null)
            {{-- PHP of the package (E41 §7.5, §8) --}}
            @php
                $phpVersions = old('php_versions', ($values['PHP_VERSIONS'] ?? '*') === '*' ? [] : explode(',', $values['PHP_VERSIONS']));
                $phpProfiles = old('php_profiles', ($values['PHP_PROFILES'] ?? 'default') === '*' ? [] : explode(',', $values['PHP_PROFILES']));
                $allVersions = (bool) old('php_versions_all', ($values['PHP_VERSIONS'] ?? '*') === '*');
                $allProfiles = (bool) old('php_profiles_all', ($values['PHP_PROFILES'] ?? 'default') === '*');
                $statusNote = ['security' => __('security fixes only'), 'eol' => __('end of life')];
                $profileChoices = array_values(array_unique([...$php['profiles'], ...array_filter($phpProfiles)]));
            @endphp
            <x-ap-card :title="__('PHP')" icon="code">
                <input type="hidden" name="php_group" value="1">
                @if ($php['own'] !== null)
                    <p class="muted">{{ sprintf(__('Your plan: versions %1$s, profiles %2$s.'), $php['own']['PHP_VERSIONS'], $php['own']['PHP_PROFILES']) }}</p>
                @endif
                <fieldset class="ap-field ap-fieldset" x-data="{ all: false }" x-init="all = Boolean($el.querySelector('input[name=php_versions_all]')?.checked)">
                    <legend class="ap-label">{{ __('PHP versions') }}</legend>
                    @if ($php['own'] === null || $php['own']['PHP_VERSIONS'] === '*')
                        <div class="ap-settings">
                            <x-ap-switch name="php_versions_all" :label="__('Every installed version')" :description="__('Versions installed later too, but no version at its end of life: to allow one, turn this off and tick the versions.')" :checked="$allVersions" x-model="all" />
                        </div>
                    @endif
                    <div class="ap-check-grid" x-show="! all">
                        @foreach (array_unique([...array_keys($php['versions']), ...array_filter($phpVersions)]) as $version)
                            <x-ap-checkbox name="php_versions[]" :id="'pkg-php-'.str_replace('.', '-', $version)" :value="$version" :label="'PHP '.$version"
                                :checked="in_array($version, $phpVersions, true)" :hint="$statusNote[$php['versions'][$version] ?? ''] ?? null" />
                        @endforeach
                    </div>
                    @error('php_versions')<p class="ap-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>
                <fieldset class="ap-field ap-fieldset" x-data="{ all: false }" x-init="all = Boolean($el.querySelector('input[name=php_profiles_all]')?.checked)">
                    <legend class="ap-label">{{ __('PHP-FPM profiles') }}</legend>
                    @if ($php['own'] === null || $php['own']['PHP_PROFILES'] === '*')
                        <div class="ap-settings">
                            <x-ap-switch name="php_profiles_all" :label="__('Every profile')" :description="__('Profiles added later too.')" :checked="$allProfiles" x-model="all" />
                        </div>
                    @endif
                    <div class="ap-check-grid" x-show="! all">
                        @foreach ($profileChoices as $profile)
                            <x-ap-checkbox name="php_profiles[]" :id="'pkg-profile-'.$profile" :value="$profile" :label="$profile" :checked="in_array($profile, $phpProfiles, true)" />
                        @endforeach
                    </div>
                    @error('php_profiles')<p class="ap-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>
                <x-ap-select name="php_default_profile" :label="__('Default profile')" :options="array_combine($profileChoices, $profileChoices)"
                    :value="$values['PHP_DEFAULT_PROFILE'] ?? 'default'" :hint="__('The profile of new web domains; it must be one of the allowed profiles.')" />
                {{-- Maximums of the per-domain PHP settings (E19 §6) --}}
                <p class="muted">{{ __('Users may set these PHP values for each of their web domains, up to the maximums below.') }}</p>
                <div class="ap-form-grid">
                    @foreach ([
                        'PHP_MAX_MEMORY' => [__('Maximum memory_limit'), __('A size such as 512M, or unlimited')],
                        'PHP_MAX_UPLOAD' => [__('Maximum upload and post size'), __('upload_max_filesize and post_max_size; a size such as 256M, or unlimited')],
                        'PHP_MAX_EXECUTION_TIME' => [__('Maximum max_execution_time'), __('Seconds, or unlimited')],
                        'PHP_MAX_INPUT_VARS' => [__('Maximum max_input_vars'), __('A number, or unlimited')],
                    ] as $key => [$label, $hint])
                        <x-ap-input :name="strtolower($key)" :label="$label" :value="$values[$key] ?? \App\Services\Php\PhpManager::SETTING_MAXIMUMS[$key]" mono
                            :hint="$php['own'] !== null ? sprintf(__('Your plan: %s'), $php['own'][$key]) : $hint" />
                    @endforeach
                </div>
            </x-ap-card>
        @endif

        @if ($resellerSettings)
            <x-ap-card :title="__('Reseller')" icon="users">
                <p class="muted">{{ __('A user on this package can resell hosting when the package allows customers.') }}</p>
                <div class="ap-form-grid">
                    <x-ap-unlimited-input name="users" :label="__('Customers')" :value="$values['USERS']" :hint="__('0: this package is not for resellers.')" />
                    <x-ap-input name="reseller_packages" :label="__('Packages the reseller may assign')" :value="$values['RESELLER_PACKAGES']" mono
                        :hint="__('Comma separated names, or * for every package available to resellers.')" />
                </div>
                <div class="ap-settings">
                    <x-ap-switch name="reseller_visible" :label="__('Available to resellers')" :description="__('Resellers whose packages allow * may give this package to their customers.')"
                        :checked="$values['RESELLER_VISIBLE'] === 'yes'" />
                </div>
            </x-ap-card>
        @endif

        <div class="ap-form-actions">
            <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
            <x-ap-button :href="route('packages.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
        </div>
    </x-ap-form>
</x-ap-page>
