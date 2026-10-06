{{-- Create a user or customer: account, package, optional first web domain and database (E11 spec §7.3). --}}
@php
    $title = $isReseller ? __('Add Customer') : __('Add User');
    $steps = array_values(array_filter([
        __('Account'),
        __('Package'),
        $hasWeb ? __('Web domain') : null,
        $hasDb ? __('Database') : null,
        __('Review'),
    ]));
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => $isReseller ? __('Customers') : __('Users'), 'url' => route('users.index')], ['label' => $title]]"
    :description="$isReseller ? __('The customer gets a login, a package from your plan and, if you like, a first website and database.') : null">
    <x-ap-card>
        <x-ap-form :action="route('users.store')" :focus-first="true">
            <x-ap-wizard :steps="$steps" :submit-label="$title">
                <fieldset data-step class="ap-fieldset-plain">
                    <legend class="sr-only">{{ __('Account') }}</legend>
                    <div class="ap-form-grid">
                        <x-ap-input name="username" :label="__('Username')" required mono autocomplete="off" spellcheck="false"
                            :hint="__('Lowercase letters, digits, dots, dashes and underscores.')" />
                        <x-ap-input name="name" :label="__('Name')" required />
                        <x-ap-input name="email" type="email" :label="__('Email')" required />
                        <x-ap-select name="language" :label="__('Language')" :options="\App\View\ShellComposer::languageOptions(array_keys($languages))" :value="$defaultLanguage" />
                    </div>
                    <x-ap-password name="password" :label="__('Password')" required :generate="true"
                        :hint="__('At least 8 characters with upper and lower case letters and a digit.')" />
                </fieldset>

                <fieldset data-step class="ap-fieldset-plain" @if ($quotaUrl) x-data="{ problem: '', async check(pkg) { try { const r = await fetch(@js($quotaUrl) + '?package=' + encodeURIComponent(pkg), { headers: { Accept: 'application/json' } }); const j = await r.json(); this.problem = j.ok ? '' : j.message; } catch (e) { this.problem = ''; } } }" x-init="check($refs.pkg.value)" @endif>
                    <legend class="sr-only">{{ __('Package') }}</legend>
                    @if ($quotaUrl)
                        <x-ap-select name="package" :label="__('Package')" :options="$packages" :value="$defaultPackage" required
                            x-ref="pkg" x-on:change="check($event.target.value)" />
                        <p class="ap-error" role="status" x-show="problem" x-text="problem" x-cloak></p>
                    @else
                        <x-ap-select name="package" :label="__('Package')" :options="$packages" :value="$defaultPackage" required />
                    @endif
                    @if ($isAdmin)
                        <div class="ap-form-grid">
                            <x-ap-select name="role" :label="__('Role')" :options="['user' => __('User'), 'reseller' => __('Reseller'), 'admin' => __('Administrator')]" value="user"
                                :hint="__('A reseller needs a package that allows customers.')" />
                            <x-ap-select name="owner" :label="__('Owner')" :options="$owners" :value="$rootUser" :hint="__('Only for users: the reseller who hosts them.')" />
                        </div>
                        <div class="ap-settings">
                            <x-ap-switch name="login_disabled" :label="__('Do not allow user to log in to Control Panel')" />
                        </div>
                    @endif
                </fieldset>

                @if ($hasWeb)
                    <fieldset data-step class="ap-fieldset-plain">
                        <legend class="sr-only">{{ __('Web domain') }}</legend>
                        <x-ap-input name="domain" :label="__('First web domain')" mono autocomplete="off" spellcheck="false"
                            :hint="__('Optional. Without www. An alias for www is added for you.')" />
                        <div class="ap-row ap-checks">
                            @if ($hasDns)
                                <x-ap-checkbox name="dns" :label="__('Create DNS zone')" :checked="true" />
                            @endif
                            @if ($hasMail)
                                <x-ap-checkbox name="mail" :label="__('Create mail domain')" :checked="true" />
                            @endif
                        </div>
                    </fieldset>
                @endif

                @if ($hasDb)
                    <fieldset data-step class="ap-fieldset-plain">
                        <legend class="sr-only">{{ __('Database') }}</legend>
                        <p class="muted">{{ __('Optional. The user name is put in front of the database and its user.') }}</p>
                        <div class="ap-form-grid">
                            <x-ap-input name="database" :label="__('Database')" mono autocomplete="off" />
                            <x-ap-input name="db_user" :label="__('Database user')" mono autocomplete="off" />
                        </div>
                        <x-ap-password name="db_password" :label="__('Database password')" :generate="true" />
                    </fieldset>
                @endif

                <fieldset data-step class="ap-fieldset-plain">
                    <legend class="sr-only">{{ __('Review') }}</legend>
                    <x-ap-input name="notify" type="email" :label="__('Send welcome e-mail to')" :hint="__('Optional. The e-mail contains the login details and the password.')" />
                    <p class="muted">{{ __('Each step runs in order. If a later step fails, the account stays and you can finish on its page.') }}</p>
                </fieldset>
            </x-ap-wizard>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
