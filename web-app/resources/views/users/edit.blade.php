{{--
    Edit a user or "My account" (web/edit/user, E11 spec §7.4–7.5): one form
    per tab, each posting to its own route and running only its own commands.
--}}
@php
    $params = ['user' => $record->name];
    $tabs = array_filter([
        'general' => __('General'),
        'package' => $can['changePackage'] && $packages !== [] ? __('Package') : null,
        'nameservers' => $can['changeNameservers'] ? __('Name servers') : null,
        'security' => $can['update'] ? __('Security') : null,
        'administration' => $can['administer'] ? __('Administration') : null,
    ]);
    $locked = ! $can['update'];
    $listTitle = $principal->actingRole()->value === 'reseller' ? __('Customers') : __('Users');
    $title = $isSelf ? __('My account') : $record->name;
    $crumbs = $isSelf
        ? [['label' => __('My account')]]
        : [['label' => $listTitle, 'url' => route('users.index')], ['label' => $record->name, 'url' => route('users.show', $params)], ['label' => __('Edit')]];
@endphp
<x-ap-page :title="$title" :crumbs="$crumbs" :eyebrow="$isSelf ? $record->name : __('Package').' · '.$packageLabel">
    @if ($locked)
        <x-ap-alert tone="info" :title="__('Read-only')">{{ __('You can view these settings but not change them.') }}</x-ap-alert>
    @endif

    <x-ap-tabs :label="__('Account settings')" :tabs="$tabs" initial="general">
        <x-ap-tab-panel name="general">
            <x-ap-card :title="__('General')" icon="user-circle">
                <x-ap-form :action="route('users.update.general', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <div class="ap-form-grid">
                            <x-ap-input name="name" :label="__('Name')" :value="$record->fullName()" bag="general" required />
                            <x-ap-input name="email" type="email" :label="__('Email')" :value="$record->email()" bag="general" required />
                            <x-ap-select name="language" :label="__('Language')" :options="\App\View\ShellComposer::languageOptions(array_keys($languages))" :value="$record->language()" bag="general" />
                        </div>
                        <x-ap-password name="password" :label="__('New password')" bag="general" :generate="true"
                            :hint="__('Leave empty to keep the current password.')" />
                    </fieldset>
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>

        @isset($tabs['package'])
            <x-ap-tab-panel name="package">
                <x-ap-card :title="__('Package')" icon="package">
                    <x-ap-form :action="route('users.update.package', $params)">
                        <x-ap-select name="package" :label="__('Package')" :options="$packages" :value="$record->package()" bag="package"
                            :hint="__('Usage must fit into the new package.')" />
                        @include('users.partials.usage', ['record' => $record])
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        @endisset

        @isset($tabs['nameservers'])
            <x-ap-tab-panel name="nameservers">
                <x-ap-card :title="__('Name servers')" icon="dns">
                    <x-ap-form :action="route('users.update.nameservers', $params)">
                        <x-ap-textarea name="nameservers" :label="__('Name servers')" :value="implode(PHP_EOL, $record->nameservers())" rows="4" mono bag="nameservers"
                            :hint="__('Two to eight, one per line. New DNS zones of this user use them.')" />
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        @endisset

        @isset($tabs['security'])
            <x-ap-tab-panel name="security">
                <x-ap-card :title="__('Security')" icon="shield" x-data="{ iplist: {{ $record->loginUsesIpList() ? 'true' : 'false' }} }">
                    <x-ap-form :action="route('users.update.security', $params)">
                        <div class="ap-settings">
                            @if ($can['manageSecurity'])
                                <x-ap-switch name="login_disabled" :label="__('Do not allow user to log in to Control Panel')" :checked="$record->loginDisabled()" bag="security" />
                            @endif
                            <x-ap-switch name="use_iplist" :label="__('Use IP address allow list for login attempts')" :checked="$record->loginUsesIpList()" bag="security" x-model="iplist" />
                        </div>
                        <div x-show="iplist">
                            <x-ap-textarea name="allowed_ips" :label="__('Allowed IP addresses')" :value="str_replace(',', PHP_EOL, $record->get('LOGIN_ALLOW_IPS'))" rows="3" mono bag="security"
                                :hint="__('One IPv4 address per line.')" />
                        </div>
                        @if ($record->twoFactor() && ($isSelf || $can['manageSecurity']))
                            <div class="ap-settings">
                                <x-ap-switch name="twofa_off" :label="__('Turn off two-factor authentication')" :description="__('The account then signs in with the password only.')" bag="security" />
                            </div>
                        @endif
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        @endisset

        @isset($tabs['administration'])
            <x-ap-tab-panel name="administration">
                <x-ap-card :title="__('Administration')" icon="sliders">
                    <x-ap-form :action="route('users.update.administration', $params)">
                        <div class="ap-form-grid">
                            @if ($can['changeRole'])
                                <x-ap-select name="role" :label="__('Role')" :options="$roles" :value="$record->role()->value" bag="administration"
                                    :hint="__('A reseller needs a package that allows customers; a reseller with customers stays a reseller.')" />
                            @endif
                            @if ($can['changeOwner'])
                                <x-ap-select name="owner" :label="__('Owner')" :options="$owners" :value="$currentOwner" bag="administration" />
                            @endif
                            @if ($shells !== [])
                                <x-ap-select name="shell" :label="__('SSH Access')" :options="$shells" :value="$record->get('SHELL')" bag="administration" />
                            @endif
                            @if ($phpVersions !== [])
                                <x-ap-select name="phpcli" :label="__('PHP CLI Version')" :options="$phpVersions" :value="$record->get('PHPCLI')" bag="administration" placeholder="—" />
                            @endif
                        </div>
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        @endisset
    </x-ap-tabs>
</x-ap-page>
