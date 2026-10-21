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
    // The login policies sit in the Login card above
    $groups = [
        'protection' => __('System Protection'),
        'users' => __('Users'),
        'domains' => __('Domains'),
    ];
    $bacon = ($config['POLICY_SYSTEM_ENABLE_BACON'] ?? '') === 'true';
    $value = fn (string $key) => ($config[$key] ?? '') === 'yes' ? 'yes' : 'no';
    // One address per line; built here because a "\n" inside the component attribute breaks the tag
    $allowedIps = str_replace(',', PHP_EOL, $config['API_ALLOWED_IP'] ?? '');
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Security')]]">
    @include('server.settings.partials.nav')

    <x-ap-form :action="route('server.settings.update.security')" class="ap-stack">
        <fieldset class="ap-fieldset-plain ap-stack" @disabled(! $canEdit)>
            <x-ap-card :title="__('API')" icon="code">
                <div class="ap-form-grid">
                    <x-ap-select name="API_SYSTEM" :label="__('API')" :value="$config['API_SYSTEM'] ?? '0'"
                        :options="['0' => __('Disabled'), '1' => __('Enabled for the system administrator'), '2' => __('Enabled for every user')]" />
                    <x-ap-select name="API_V1" :label="__('REST API v1')" :options="$yesNo" :value="$value('API_V1')"
                        :hint="__('The /api/ endpoint of earlier releases. REST API v2 replaces it; phpMyAdmin single sign-on works without it.')" />
                    <x-ap-select name="API" :label="__('Legacy API')" :options="$yesNo" :value="($config['API'] ?? '') === 'yes' ? 'yes' : 'no'"
                        :hint="__('The older API that signs in with a user name and password or a hash key. Needs REST API v1.')" />
                </div>
                <x-ap-textarea name="API_ALLOWED_IP" :label="__('Allowed IP addresses for API')" :value="$allowedIps" rows="3" mono
                    :hint="__('One address per line; allow-all accepts every address.')" />
            </x-ap-card>

            <x-ap-card :title="__('Login')" icon="lock">
                <div class="ap-form-grid">
                    <x-ap-select name="LOGIN_STYLE" :label="__('Login screen style')" :options="['default' => __('Default'), 'old' => __('Old Style')]" :value="$config['LOGIN_STYLE'] ?? 'default'" />
                    <x-ap-input name="INACTIVE_SESSION_TIMEOUT" type="number" :label="__('Inactive session timeout')" :value="$config['INACTIVE_SESSION_TIMEOUT'] ?? '60'" :suffix="__('minutes')" />
                    <x-ap-select name="POLICY_CSRF_STRICTNESS" :label="__('Prevent CSRF')" :value="$config['POLICY_CSRF_STRICTNESS'] ?? '1'"
                        :options="['0' => __('Disabled'), '1' => __('Enabled'), '2' => __('Strict')]" />
                    @foreach (\App\Services\Server\ServerSettings::SECURITY_POLICIES['login'] as $key)
                        <x-ap-select :name="$key" :label="$labels[$key]" :options="$yesNo" :value="$value($key)" />
                    @endforeach
                </div>
            </x-ap-card>

            {{-- Mail and FTP logins over TLS only (E39 W5 §12.2); Dovecot treats webmail on the server as encrypted --}}
            <x-ap-card :title="__('Mail and FTP')" icon="mail">
                <div class="ap-form-grid">
                    <x-ap-select name="TLS_LOGIN_ONLY" :label="__('Mail and FTP logins over TLS only')" :options="$yesNo" :value="$value('TLS_LOGIN_ONLY')"
                        :hint="__('Mail clients and FTP clients that sign in without encryption stop working; webmail keeps working.')" />
                </div>
            </x-ap-card>

            @if ($protectAdmin)
                {{-- HSTS of the panel (E39 W5 §9.1): browsers that saw it refuse an untrusted certificate for a day --}}
                <x-ap-card :title="__('HTTPS of the panel')" icon="lock">
                    <div class="ap-form-grid">
                        <x-ap-select name="PANEL_HSTS" :label="__('Send HSTS (Strict-Transport-Security)')" :options="$yesNo" :value="$value('PANEL_HSTS')"
                            :disabled="$selfSigned && $value('PANEL_HSTS') === 'no'"
                            :hint="$selfSigned
                                ? __('The panel certificate is self-signed. Install a trusted certificate on the SSL tab before you turn HSTS on.')
                                : __('Browsers then refuse the panel for a day whenever its certificate is not trusted, for example after it expires.')" />
                    </div>
                </x-ap-card>
            @endif
            @foreach ($groups as $group => $title)
                @continue($group === 'protection' && ! $protectAdmin)
                <x-ap-card :title="$title" icon="shield">
                    <div class="ap-form-grid">
                        @foreach (\App\Services\Server\ServerSettings::SECURITY_POLICIES[$group] as $key)
                            @continue($key === 'POLICY_USER_VIEW_SUSPENDED' && ! $bacon)
                            <x-ap-select :name="$key" :label="$labels[$key]" :options="$yesNo" :value="$value($key)" />
                        @endforeach
                        @if ($group === 'users')
                            <x-ap-input name="POLICY_RESELLER_PACKAGE_SHELLS" :label="__('Shells resellers may give in their packages')" mono
                                :value="$config['POLICY_RESELLER_PACKAGE_SHELLS'] ?? 'nologin'"
                                :hint="__('Comma list of shells of this server, such as nologin or nologin,bash. A package keeps the shell it already has.')" />
                        @endif
                    </div>
                </x-ap-card>
            @endforeach
        </fieldset>
        @if ($canEdit)
            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
        @endif
    </x-ap-form>
</x-ap-page>
