{{--
    Add or edit a package (web/add/package, web/edit/package) with the reseller settings of E11 spec §8.
    One long form in sections with in-page links, the features behind their switches and the save bar
    kept in view (apFormState).
--}}
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
    ];
    $sizes = ['M' => 'MB', 'G' => 'GB'];
    $own = fn (string $key) => isset($ownLimits[$key]) ? sprintf(__('Your plan: %s'), $ownLimits[$key]) : null;
    $plan = fn (?array $ownPlan, string $key, string $hint) => $ownPlan !== null ? sprintf(__('Your plan: %s'), $ownPlan[$key]) : $hint;
    $options = fn (array $list) => array_combine($list, $list);
    $users = $record?->users ?? 0;
    $sections = array_filter([
        'package-general' => __('General'),
        'package-limits' => __('Limits'),
        'package-storage' => $resources ? __('Disk and resources') : __('Disk and bandwidth'),
        'package-backups' => __('Backups'),
        'package-network' => __('Network'),
        'package-node' => __('Node.js'),
        'package-docker' => __('Docker apps'),
        'package-app-processes' => __('App processes'),
        'package-object-storage' => __('Object storage'),
        'package-php' => $php !== null ? __('PHP') : null,
        'package-reseller' => $resellerSettings ? __('Reselling') : null,
    ]);
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Packages'), 'url' => route('packages.index')], ['label' => $title]]"
    :eyebrow="$isNew ? null : __('Package')"
    :description="$isNew
        ? __('A package sets the limits and the features of the users it is given to.')
        : match (true) {
            $users === 0 => __('No user has this package yet.'),
            $users === 1 => __('1 user has this package; saving changes it for them.'),
            default => sprintf(__('%d users have this package; saving changes it for each of them.'), $users),
        }">
    @if ($can['copy'] || $can['delete'])
        <x-slot:actions>
            @if ($can['copy'])
                <details class="ap-dropdown">
                    <summary class="ap-btn"><x-ap-icon name="copy" />{{ __('Copy') }}</summary>
                    <div class="ap-menu ap-copy-menu" role="group" aria-label="{{ sprintf(__('Copy package %s'), $name) }}">
                        <form method="post" action="{{ route('packages.copy', ['package' => $record->name]) }}" class="ap-switch-form">
                            @csrf
                            <label class="sr-only" for="package-copy-name">{{ __('New package name') }}</label>
                            <input class="ap-input" id="package-copy-name" name="name" required placeholder="{{ __('New package name') }}">
                            <button type="submit" class="ap-btn ap-btn-sm ap-btn-primary">{{ __('Copy') }}</button>
                        </form>
                    </div>
                </details>
            @endif
            @if ($can['delete'] && $deleteRefusal === null)
                <x-ap-button variant="danger" icon="trash" :form-action="route('packages.destroy', ['package' => $record->name])" form-method="DELETE"
                    :confirm="sprintf(__('Are you sure you want to delete package %s?'), $record->name)">{{ __('Delete') }}</x-ap-button>
            @elseif ($can['delete'])
                <span class="ap-btn ap-btn-danger is-disabled" role="button" aria-disabled="true" title="{{ $deleteRefusal }}" aria-describedby="package-delete-reason">
                    <x-ap-icon name="trash" />{{ __('Delete') }}
                </span>
                <span class="sr-only" id="package-delete-reason">{{ $deleteRefusal }}</span>
            @endif
        </x-slot:actions>
    @endif

    <x-ap-form :action="$isNew ? route('packages.store') : route('packages.update', ['package' => $record->name])" :focus-first="$isNew"
        class="ap-long-form" x-data="apFormState()">
        <div class="ap-form-layout">
            <x-ap-section-nav :sections="$sections" :label="__('Package sections')" />

            <div class="ap-form-sections">
                <x-ap-card :title="__('General')" icon="package" id="package-general">
                    <div class="ap-form-grid">
                        @if ($isNew || $canRename)
                            <x-ap-input name="name" :label="__('Package Name')" :value="$name" required mono />
                        @else
                            <input type="hidden" name="name" value="{{ $name }}">
                            <x-ap-input name="name_shown" :label="__('Package Name')" :value="$name" mono disabled />
                        @endif
                        <x-ap-select name="shell" :label="__('SSH Access')" :options="$options($templates['shells'])" :value="$values['SHELL']" />
                    </div>
                    <div class="ap-form-grid">
                        <x-ap-select name="web_template" :label="__('Web Template')" :options="$options($templates['web'])" :value="$values['WEB_TEMPLATE']" />
                        @if ($templates['backend'] !== [])
                            <x-ap-select name="backend_template" :label="__('Backend Template')" :options="$options($templates['backend'])" :value="$values['BACKEND_TEMPLATE']" />
                        @endif
                        @if ($templates['proxy'] !== [])
                            <x-ap-select name="proxy_template" :label="__('Proxy Template')" :options="$options($templates['proxy'])" :value="$values['PROXY_TEMPLATE']" />
                        @endif
                        <x-ap-select name="dns_template" :label="__('DNS Template')" :options="$options($templates['dns'])" :value="$values['DNS_TEMPLATE']" />
                    </div>
                </x-ap-card>

                <x-ap-card :title="__('Limits')" icon="sliders" id="package-limits">
                    <p class="ap-card-lead">{{ __('How many of each a user of this package may have.') }}</p>
                    <div class="ap-form-grid ap-form-grid-3">
                        @foreach ($limits as $key => [$label, $suffix])
                            <x-ap-unlimited-input :name="strtolower($key)" :label="$label" :value="$values[$key]" :suffix="$suffix" :hint="$own($key)" />
                        @endforeach
                        <x-ap-input name="rate_limit" type="number" :label="__('Mail rate limit')" :value="$values['RATE_LIMIT']" :suffix="__('per hour')" :hint="$own('RATE_LIMIT') ?? __('Emails each mail account may send.')" required />
                    </div>
                </x-ap-card>

                <x-ap-card :title="$sections['package-storage']" icon="disk" id="package-storage">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-unlimited-input name="disk_quota" :label="__('Quota')" :value="$values['DISK_QUOTA']" suffix="MB" :hint="$own('DISK_QUOTA') ?? __('Disk space of the files, mail and databases together.')" />
                        <x-ap-unlimited-input name="bandwidth" :label="__('Bandwidth')" :value="$values['BANDWIDTH']" suffix="MB" :hint="$own('BANDWIDTH') ?? __('Traffic per month.')" />
                    </div>
                    @if ($resources)
                        <div class="ap-form-grid ap-form-grid-3">
                            <x-ap-limit-input name="cpu_quota" :label="__('CPU quota')" :value="$values['CPU_QUOTA']" :units="['%' => '%']" :hint="__('Share of one CPU, e.g. 50; 200 for two CPUs.')" />
                            <x-ap-limit-input name="cpu_quota_period" :label="__('CPU quota period')" :value="$values['CPU_QUOTA_PERIOD']" :units="['ms' => 'ms', 's' => 's']" />
                            <x-ap-limit-input name="memory_limit" :label="__('Memory limit')" :value="$values['MEMORY_LIMIT']" :units="$sizes" />
                            <x-ap-limit-input name="swap_limit" :label="__('Swap limit')" :value="$values['SWAP_LIMIT']" :units="$sizes" />
                        </div>
                    @endif
                </x-ap-card>

                <x-ap-card :title="__('Backups')" icon="history" id="package-backups">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-input name="backups" type="number" :label="__('Backups kept')" :value="$values['BACKUPS']" :hint="$own('BACKUPS') ?? __('The oldest backup goes when a new one is made.')" required />
                        <x-ap-select name="backup_frequency" :label="__('Backup frequency at most')" :value="$values['BACKUP_FREQUENCY'] ?? 'daily'"
                            :options="['hourly' => __('Every hour'), 'daily' => __('Every day'), 'weekly' => __('Every week')]"
                            :hint="$ownStorage !== null ? sprintf(__('Your plan: %s'), $ownStorage['BACKUP_FREQUENCY']) : __('Scheduled backup jobs skip an account backed up more recently than this.')" />
                    </div>
                    <div class="ap-settings">
                        <x-ap-switch name="backups_incremental" :label="__('Incremental backups')" :description="__('Back up only what changed since the last backup, in a restic repository.')"
                            :checked="$values['BACKUPS_INCREMENTAL'] === 'yes'" />
                    </div>
                </x-ap-card>

                <x-ap-card :title="__('Network')" icon="hash" id="package-network">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-unlimited-input name="ports" :label="__('Ports')" :value="$values['PORTS'] ?? '0'"
                            :hint="$ownPorts !== null ? sprintf(__('Your plan: %s'), $ownPorts) : __('Allocated to each new user of this package.')" />
                    </div>
                    <x-ap-textarea name="nameservers" :label="__('Name servers')" :value="str_replace(',', PHP_EOL, $values['NS'])" rows="3" mono
                        :hint="__('Two to eight, one per line.')" />
                </x-ap-card>

                <x-ap-feature-card id="package-node" :title="__('Node.js')" icon="hexagon" name="node"
                    :description="__('Users of this package may install Node.js versions and global npm packages.')"
                    :checked="$values['NODE'] === 'yes'" :disabled="$ownNode !== null && $ownNode['NODE'] !== 'yes'"
                    :note="$ownNode !== null && $ownNode['NODE'] !== 'yes' ? __('Node.js is not included in your plan.') : null">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-unlimited-input name="node_versions" :label="__('Node.js versions')" :value="$values['NODE_VERSIONS']"
                            :hint="$plan($ownNode, 'NODE_VERSIONS', __('Runtimes installed at the same time.'))" />
                        <x-ap-unlimited-input name="node_apps" :label="__('Node.js apps')" :value="$values['NODE_APPS'] ?? '3'"
                            :hint="$plan($ownNode, 'NODE_APPS', __('Apps bound to web domains, run by pm2.'))" />
                        <x-ap-input name="node_allowed" :label="__('Allowed majors')" :value="$values['NODE_ALLOWED']" mono
                            :hint="$plan($ownNode, 'NODE_ALLOWED', __('lts for the supported LTS lines, * for every version, or majors such as 22,24.'))" />
                    </div>
                </x-ap-feature-card>

                <x-ap-feature-card id="package-docker" :title="__('Docker apps')" icon="container" name="docker"
                    :description="__('Users of this package may install apps of the Docker app store on their domains.')"
                    :checked="($values['DOCKER'] ?? 'no') === 'yes'" :disabled="$ownDocker !== null && $ownDocker['DOCKER'] !== 'yes'"
                    :note="$ownDocker !== null && $ownDocker['DOCKER'] !== 'yes' ? __('Docker apps are not included in your plan.') : null">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-unlimited-input name="docker_apps" :label="__('Apps')" :value="$values['DOCKER_APPS'] ?? '0'"
                            :hint="$plan($ownDocker, 'DOCKER_APPS', __('Apps installed at the same time.'))" />
                        <x-ap-limit-input name="docker_cpus" :label="__('CPUs per app')" :value="$values['DOCKER_CPUS'] ?? 'unlimited'" :suffix="__('CPUs')"
                            :hint="$plan($ownDocker, 'DOCKER_CPUS', __('A number such as 0.5 or 2.'))" />
                        <x-ap-limit-input name="docker_memory" :label="__('Memory per app')" :value="$values['DOCKER_MEMORY'] ?? 'unlimited'" :units="$sizes" bare="M"
                            :hint="$plan($ownDocker, 'DOCKER_MEMORY', __('Within the memory limit of the user.'))" />
                    </div>
                </x-ap-feature-card>

                <x-ap-feature-card id="package-app-processes" :title="__('App processes')" icon="terminal" name="app_processes"
                    :description="__('Users of this package may run long-running commands of their domains, such as Laravel Octane or queue workers, as their own user.')"
                    :checked="($values['APP_PROCESSES'] ?? 'no') === 'yes'" :disabled="$ownProcesses !== null && $ownProcesses['APP_PROCESSES'] !== 'yes'"
                    :note="$ownProcesses !== null && $ownProcesses['APP_PROCESSES'] !== 'yes' ? __('App processes are not included in your plan.') : null">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-unlimited-input name="app_processes_max" :label="__('Processes')" :value="$values['APP_PROCESSES_MAX'] ?? '3'"
                            :hint="$plan($ownProcesses, 'APP_PROCESSES_MAX', __('Web processes and workers together.'))" />
                        <x-ap-limit-input name="app_processes_cpus" :label="__('CPUs per process')" :value="$values['APP_PROCESSES_CPUS'] ?? '1'" :suffix="__('CPUs')"
                            :hint="$plan($ownProcesses, 'APP_PROCESSES_CPUS', __('A number such as 0.5 or 2.'))" />
                        <x-ap-limit-input name="app_processes_memory" :label="__('Memory per process')" :value="$values['APP_PROCESSES_MEMORY'] ?? 'unlimited'" :units="$sizes" bare="M"
                            :hint="$plan($ownProcesses, 'APP_PROCESSES_MEMORY', __('Within the memory limit of the user.'))" />
                    </div>
                </x-ap-feature-card>

                {{-- Object storage (E44 spec §6) --}}
                <x-ap-feature-card id="package-object-storage" :title="__('Object storage')" icon="archive" name="object_storage"
                    :description="__('Users of this package may create S3-compatible buckets and S3 keys on this server.')"
                    :checked="($values['OBJECT_STORAGE'] ?? 'no') === 'yes'" :disabled="$ownStorage !== null && $ownStorage['OBJECT_STORAGE'] !== 'yes'"
                    :note="$ownStorage !== null && $ownStorage['OBJECT_STORAGE'] !== 'yes' ? __('Object storage is not included in your plan.') : null">
                    <div class="ap-form-grid ap-form-grid-3">
                        <x-ap-unlimited-input name="object_storage_buckets" :label="__('Buckets')" :value="$values['OBJECT_STORAGE_BUCKETS'] ?? '3'"
                            :hint="$plan($ownStorage, 'OBJECT_STORAGE_BUCKETS', __('Buckets per user.'))" />
                        <x-ap-limit-input name="object_storage_quota" :label="__('Storage quota')" :value="$values['OBJECT_STORAGE_QUOTA'] ?? '1G'" :units="$sizes + ['T' => 'TB']" bare="M"
                            :hint="$plan($ownStorage, 'OBJECT_STORAGE_QUOTA', __('All buckets of a user together.'))" />
                    </div>
                    <div class="ap-settings">
                        <x-ap-switch name="object_storage_backup" :label="__('Back up bucket contents')" :description="__('User backups include the objects of every bucket; they can make backups much larger.')"
                            :checked="($values['OBJECT_STORAGE_BACKUP'] ?? 'no') === 'yes'" :disabled="$ownStorage !== null && $ownStorage['OBJECT_STORAGE_BACKUP'] !== 'yes'" />
                    </div>
                </x-ap-feature-card>

                @if ($php !== null)
                    {{-- PHP of the package (E41 §7.5, §8) --}}
                    @php
                        $phpVersions = old('php_versions', ($values['PHP_VERSIONS'] ?? '*') === '*' ? [] : explode(',', $values['PHP_VERSIONS']));
                        $phpProfiles = old('php_profiles', ($values['PHP_PROFILES'] ?? 'default') === '*' ? [] : explode(',', $values['PHP_PROFILES']));
                        $allVersions = (bool) old('php_versions_all', ($values['PHP_VERSIONS'] ?? '*') === '*');
                        $allProfiles = (bool) old('php_profiles_all', ($values['PHP_PROFILES'] ?? 'default') === '*');
                        $statusNote = ['security' => __('security fixes only'), 'eol' => __('end of life')];
                        $profileChoices = array_values(array_unique([...$php['profiles'], ...array_filter($phpProfiles)]));
                        $maximum = fn (string $key) => $values[$key] ?? \App\Services\Php\PhpManager::SETTING_MAXIMUMS[$key];
                        $phpPlan = fn (string $key, string $hint) => $php['own'] !== null ? sprintf(__('Your plan: %s'), $php['own'][$key]) : $hint;
                    @endphp
                    <x-ap-card :title="__('PHP')" icon="code" id="package-php">
                        <input type="hidden" name="php_group" value="1">
                        @if ($php['own'] !== null)
                            <x-ap-alert tone="info">{{ sprintf(__('Your plan: versions %1$s, profiles %2$s.'), $php['own']['PHP_VERSIONS'], $php['own']['PHP_PROFILES']) }}</x-ap-alert>
                        @endif
                        <fieldset class="ap-field ap-fieldset" x-data="{ all: false }" x-init="all = Boolean($el.querySelector('input[name=php_versions_all]')?.checked)">
                            <legend class="ap-subhead">{{ __('PHP versions') }}</legend>
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
                            <legend class="ap-subhead">{{ __('PHP-FPM profiles') }}</legend>
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
                            <div class="ap-form-grid ap-form-grid-3">
                                <x-ap-select name="php_default_profile" :label="__('Default profile')" :options="array_combine($profileChoices, $profileChoices)"
                                    :value="$values['PHP_DEFAULT_PROFILE'] ?? 'default'" :hint="__('The profile of new web domains; it must be one of the allowed profiles.')" />
                            </div>
                        </fieldset>
                        {{-- Maximums of the per-domain PHP settings (E19 §6) --}}
                        <fieldset class="ap-field ap-fieldset">
                            <legend class="ap-subhead">{{ __('PHP settings of the web domains') }}</legend>
                            <p class="ap-card-lead">{{ __('Users may set these PHP values for each of their web domains, up to the maximums below.') }}</p>
                            <div class="ap-form-grid ap-form-grid-2">
                                <x-ap-limit-input name="php_max_memory" :label="__('Maximum memory_limit')" :value="$maximum('PHP_MAX_MEMORY')" :units="$sizes"
                                    :hint="$phpPlan('PHP_MAX_MEMORY', __('The memory_limit of a domain at most.'))" />
                                <x-ap-limit-input name="php_max_upload" :label="__('Maximum upload and post size')" :value="$maximum('PHP_MAX_UPLOAD')" :units="$sizes"
                                    :hint="$phpPlan('PHP_MAX_UPLOAD', __('upload_max_filesize and post_max_size.'))" />
                                <x-ap-limit-input name="php_max_execution_time" :label="__('Maximum max_execution_time')" :value="$maximum('PHP_MAX_EXECUTION_TIME')" :suffix="__('seconds')"
                                    :hint="$phpPlan('PHP_MAX_EXECUTION_TIME', __('From 1 to 86400.'))" />
                                <x-ap-limit-input name="php_max_input_vars" :label="__('Maximum max_input_vars')" :value="$maximum('PHP_MAX_INPUT_VARS')"
                                    :hint="$phpPlan('PHP_MAX_INPUT_VARS', __('From 1 to 1000000.'))" />
                            </div>
                        </fieldset>
                    </x-ap-card>
                @endif

                @if ($resellerSettings)
                    @php
                        $isReseller = ! in_array((string) \App\View\FormField::old('users', $values['USERS']), ['', '0'], true) || \App\View\FormField::old('users_unlimited') !== null;
                        $resellerPackages = trim((string) \App\View\FormField::old('reseller_packages', $values['RESELLER_PACKAGES']));
                        $picked = $resellerPackages === '*' ? [] : array_values(array_filter(preg_split('/\s*,\s*/', $resellerPackages) ?: []));
                        $choices = array_values(array_unique([...$packageChoices, ...$picked]));
                    @endphp
                    <x-ap-card :title="__('Reselling')" icon="users" id="package-reseller" x-data="{ reseller: {{ $isReseller ? 'true' : 'false' }} }">
                        <div class="ap-settings">
                            <label class="ap-setting" for="package-reseller-on">
                                <span class="ap-setting-text">
                                    <b>{{ __('Customers can be added') }}</b>
                                    <span id="package-reseller-on-hint">{{ __('A user of this package is a reseller: they add customers and give them packages.') }}</span>
                                </span>
                                <span class="ap-switch">
                                    <input type="checkbox" role="switch" id="package-reseller-on" x-model="reseller" @checked($isReseller) aria-describedby="package-reseller-on-hint">
                                </span>
                            </label>
                        </div>
                        {{-- Off: the package is not for resellers, USERS 0 --}}
                        <input type="hidden" name="users" value="0" :disabled="reseller" @disabled($isReseller)>
                        <div class="ap-feature-body ap-reseller-body" x-show="reseller" @unless ($isReseller) x-cloak @endunless>
                            <fieldset class="ap-fieldset-plain" :disabled="! reseller" @disabled(! $isReseller)>
                                <div class="ap-form-grid ap-form-grid-3">
                                    <x-ap-unlimited-input name="users" :label="__('Customers')" :value="$values['USERS'] === '0' ? '' : $values['USERS']" min="1" required
                                        :hint="__('How many customers a reseller of this package may have.')" />
                                </div>
                            </fieldset>
                            <fieldset class="ap-field ap-fieldset" x-data="apChoiceList({{ \Illuminate\Support\Js::from(['value' => $resellerPackages]) }})">
                                <legend class="ap-subhead">{{ __('Packages the reseller may assign') }}</legend>
                                <input type="hidden" name="reseller_packages" value="{{ $resellerPackages }}" :value="value">
                                <div class="ap-settings">
                                    <label class="ap-setting" for="package-reseller-all">
                                        <span class="ap-setting-text">
                                            <b>{{ __('Every package available to resellers') }}</b>
                                            <span>{{ __('Packages made available to resellers later too.') }}</span>
                                        </span>
                                        <span class="ap-switch"><input type="checkbox" role="switch" id="package-reseller-all" x-model="all" @checked($resellerPackages === '*')></span>
                                    </label>
                                </div>
                                <div class="ap-check-grid" x-show="! all" @if ($resellerPackages === '*') x-cloak @endif>
                                    @foreach ($choices as $choice)
                                        <label class="ap-check" for="package-reseller-pick-{{ $loop->index }}">
                                            <input type="checkbox" id="package-reseller-pick-{{ $loop->index }}" value="{{ $choice }}" x-model="picked" @checked(in_array($choice, $picked, true))>
                                            <span class="mono">{{ $choice }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('reseller_packages')<p class="ap-error" role="alert">{{ $message }}</p>@enderror
                            </fieldset>
                        </div>
                        <div class="ap-settings ap-settings-split">
                            <x-ap-switch name="reseller_visible" :label="__('Available to resellers')" :description="__('Resellers whose packages allow every package may give this package to their customers.')"
                                :checked="$values['RESELLER_VISIBLE'] === 'yes'" />
                        </div>
                    </x-ap-card>
                @endif

                <x-ap-form-bar>
                    <x-ap-button :href="route('packages.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                    <x-ap-button type="submit" variant="primary" icon="check">{{ $isNew ? __('Add Package') : __('Save') }}</x-ap-button>
                </x-ap-form-bar>
            </div>
        </div>
    </x-ap-form>
</x-ap-page>
