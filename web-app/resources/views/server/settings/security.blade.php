{{-- Server settings: Security and policies (web/edit/server, E7 batch 3 spec §8.2). --}}
@php
    $yesNo = ['yes' => __('Yes'), 'no' => __('No')];
    $labels = [
        'POLICY_SYSTEM_PASSWORD_RESET' => __('Allow users to reset their passwords'),
        'POLICY_SYSTEM_PROTECTED_ADMIN' => __('Restrict access to read-only for other administrators'),
        'POLICY_SYSTEM_HIDE_ADMIN' => __('Hide the system administrator account from other administrators'),
        'POLICY_SYSTEM_HIDE_SERVICES' => __('Hide the services page from other administrators'),
        'POLICY_USER_EDIT_DETAILS' => __('Allow users to edit their account details'),
        'POLICY_USER_EDIT_WEB_TEMPLATES' => __('Allow users to change templates when editing web domains'),
        'POLICY_USER_EDIT_DNS_TEMPLATES' => __('Allow users to change templates when editing DNS zones'),
        'POLICY_USER_VIEW_LOGS' => __('Allow users to view action and login history logs'),
        'POLICY_USER_DELETE_LOGS' => __('Allow users to delete log history'),
        'POLICY_USER_VIEW_SUSPENDED' => __('Allow suspended users to log in with read-only access'),
        'POLICY_BACKUP_SUSPENDED_USERS' => __('Allow suspended users to create new backups'),
        'POLICY_SYNC_ERROR_DOCUMENTS' => __('Sync error documents on update'),
        'POLICY_SYNC_SKELETON' => __('Sync the skeleton directory on update'),
        'ENFORCE_SUBDOMAIN_OWNERSHIP' => __('Enforce subdomain ownership'),
    ];
    $groups = [
        'login' => __('Login'),
        'protection' => __('System Protection'),
        'users' => __('Users'),
        'domains' => __('Domains'),
    ];
    $bacon = ($config['POLICY_SYSTEM_ENABLE_BACON'] ?? '') === 'true';
    $value = fn (string $key) => ($config[$key] ?? '') === 'yes' ? 'yes' : 'no';
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Security')]]">
    @include('server.settings.partials.nav')

    <x-ap-form :action="route('server.settings.update.security')" class="ap-stack">
        <fieldset class="ap-fieldset-plain ap-stack" @disabled(! $canEdit)>
            <x-ap-card :title="__('API')" icon="code">
                <div class="ap-form-grid">
                    <x-ap-select name="API_SYSTEM" :label="__('API')" :value="$config['API_SYSTEM'] ?? '0'"
                        :options="['0' => __('Disabled'), '1' => __('Enabled for the system administrator'), '2' => __('Enabled for every user')]" />
                    <x-ap-select name="API" :label="__('Legacy API')" :options="$yesNo" :value="($config['API'] ?? '') === 'yes' ? 'yes' : 'no'"
                        :hint="__('The hash-based API of HestiaCP 1.x.')" />
                </div>
                <x-ap-textarea name="API_ALLOWED_IP" :label="__('Allowed IP addresses for API')" :value="str_replace(',', "\n", $config['API_ALLOWED_IP'] ?? '')" rows="3" mono
                    :hint="__('One address per line; allow-all accepts every address.')" />
            </x-ap-card>

            <x-ap-card :title="__('Login')" icon="lock">
                <div class="ap-form-grid">
                    <x-ap-select name="LOGIN_STYLE" :label="__('Login screen style')" :options="['default' => __('Default'), 'old' => __('Old Style')]" :value="$config['LOGIN_STYLE'] ?? 'default'" />
                    <x-ap-input name="INACTIVE_SESSION_TIMEOUT" type="number" :label="__('Inactive session timeout')" :value="$config['INACTIVE_SESSION_TIMEOUT'] ?? '60'" :suffix="__('minutes')" />
                    <x-ap-select name="POLICY_CSRF_STRICTNESS" :label="__('Prevent CSRF')" :value="$config['POLICY_CSRF_STRICTNESS'] ?? '1'"
                        :options="['0' => __('Disabled'), '1' => __('Enabled'), '2' => __('Strict')]" />
                </div>
            </x-ap-card>

            @foreach ($groups as $group => $title)
                @continue($group === 'protection' && ! $protectAdmin)
                <x-ap-card :title="$title" icon="shield">
                    <div class="ap-form-grid">
                        @foreach (\App\Services\Server\ServerSettings::SECURITY_POLICIES[$group] as $key)
                            @continue($key === 'POLICY_USER_VIEW_SUSPENDED' && ! $bacon)
                            <x-ap-select :name="$key" :label="$labels[$key]" :options="$yesNo" :value="$value($key)" />
                        @endforeach
                    </div>
                </x-ap-card>
            @endforeach
        </fieldset>
        @if ($canEdit)
            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
        @endif
    </x-ap-form>
</x-ap-page>
