{{-- Server settings: General (web/edit/server "Basic Options", E7 batch 3 spec §8.2). --}}
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('General')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('General')" icon="sliders">
        <x-ap-form :action="route('server.settings.update.general')">
            <fieldset class="ap-fieldset-plain" @disabled(! $canEdit)>
                <div class="ap-form-grid">
                    <x-ap-input name="hostname" :label="__('Hostname')" :value="$hostname" mono required />
                    <x-ap-select name="timezone" :label="__('Time Zone')" :options="array_combine($timezones, $timezones)" :value="$timezone" required />
                    <x-ap-select name="language" :label="__('Default Language')" :options="array_combine($languages, $languages)" :value="$config['LANGUAGE'] ?? 'en'" required />
                    <x-ap-select name="theme" :label="__('Theme')" :options="array_combine($themes, array_map('ucfirst', $themes))" :value="$config['THEME'] ?? ''" required />
                </div>
                <x-ap-checkbox name="language_all" :label="__('Set as default language for all users')" />
                <x-ap-switch name="user_theme" :label="__('Allow users to change their theme')" :checked="($config['POLICY_USER_CHANGE_THEME'] ?? 'yes') !== 'no'" />
                <x-ap-switch name="debug" :label="__('Debug Mode')" :description="__('Write the commands of the panel to the log.')" :checked="($config['DEBUG_MODE'] ?? '') === 'true'" />
                <x-ap-switch name="experimental" :label="__('Enable preview features')" :description="__('Features that are still being tested.')" :checked="($config['POLICY_SYSTEM_ENABLE_BACON'] ?? '') === 'true'" />
            </fieldset>
            <dl class="ap-dl">
                <dt>{{ __('Version') }}</dt><dd class="mono">{{ $config['ARCPANEL_VERSION'] ?? '' }} ({{ $config['VERSION'] ?? '' }})</dd>
                @if (($config['RELEASE_BRANCH'] ?? 'release') !== 'release')
                    <dt>{{ __('Release') }}</dt><dd class="mono">{{ $config['RELEASE_BRANCH'] }}</dd>
                @endif
            </dl>
            @if ($canEdit)
                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
