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
        </x-ap-card>

        <x-ap-card :title="__('Limits')" icon="sliders">
            <div class="ap-form-grid">
                @foreach ($limits as $key => [$label, $suffix])
                    <x-ap-unlimited-input :name="strtolower($key)" :label="$label" :value="$values[$key]" :suffix="$suffix" :hint="$own($key)" />
                @endforeach
                <x-ap-input name="rate_limit" type="number" :label="__('Mail rate limit')" :value="$values['RATE_LIMIT']" suffix="/h" :hint="$own('RATE_LIMIT')" required />
                <x-ap-input name="backups" type="number" :label="__('Backups')" :value="$values['BACKUPS']" :hint="$own('BACKUPS')" required />
                <x-ap-unlimited-input name="ports" :label="__('Ports')" :value="$values['PORTS'] ?? '0'"
                    :hint="$ownPorts !== null ? sprintf(__('Your plan: %s'), $ownPorts) : __('Allocated to each new user of this package (E12 port registry).')" />
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
                <x-ap-input name="node_allowed" :label="__('Allowed majors')" :value="$values['NODE_ALLOWED']" mono
                    :hint="__('lts for the supported LTS lines, * for every version, or majors such as 22,24.')" />
            </div>
        </x-ap-card>

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
