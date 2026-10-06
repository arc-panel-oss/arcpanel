{{-- Server settings: White Label (web/edit/server/whitelabel, E7 batch 3 spec §8.2, D6). --}}
@php
    $titleHint = sprintf(__('Title of the browser tab; %s are replaced.'), '{{appname}}, {{hostname}}, {{ip}}, {{page}}');
    $subjectHint = sprintf(__('Subject of the panel e-mails; %s are replaced.'), '{{subject}}, {{hostname}}, {{appname}}');
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('White Label')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('White Label')" icon="tag">
        <x-ap-form :action="route('server.settings.update.white-label')">
            <fieldset class="ap-fieldset-plain" @disabled(! $canEdit)>
                <div class="ap-form-grid">
                    <x-ap-input name="APP_NAME" :label="__('Application Name')" :value="$config['APP_NAME'] ?? ''" required />
                    <x-ap-input name="TITLE" :label="__('Title')" :value="$config['TITLE'] ?? ''" required
                        :hint="$titleHint" />
                    <x-ap-input name="SUBJECT_EMAIL" :label="__('Email subject')" :value="$config['SUBJECT_EMAIL'] ?? ''" required
                        :hint="$subjectHint" />
                    <x-ap-select name="HIDE_DOCS" :label="__('Hide documentation links')" :options="['no' => __('No'), 'yes' => __('Yes')]" :value="($config['HIDE_DOCS'] ?? '') === 'yes' ? 'yes' : 'no'" />
                    <x-ap-input name="FROM_NAME" :label="__('Sender name')" :value="$config['FROM_NAME'] ?? ''" :hint="__('Default: the application name')" />
                    <x-ap-input name="FROM_EMAIL" type="email" :label="__('Sender email address')" :value="$config['FROM_EMAIL'] ?? ''" :hint="__('Default: noreply@ and the host name')" />
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
            @endif
        </x-ap-form>
    </x-ap-card>

    <x-ap-card :title="__('Logo')" icon="image">
        <p>{{ $customLogo ? __('The panel shows your custom logo.') : __('The panel shows the default logo.') }}</p>
        <p class="muted">{{ __('Put logo.svg, logo.png, logo-header.svg, favicon.png and favicon.ico into /usr/local/arcpanel/data/white-label/ on the server, then apply them.') }}</p>
        @if ($canEdit)
            <div class="ap-form-actions">
                <form method="post" action="{{ route('server.settings.logo') }}" class="ap-inline-form">
                    @csrf
                    <input type="hidden" name="action" value="apply">
                    <x-ap-button type="submit" icon="check">{{ __('Apply custom logo') }}</x-ap-button>
                </form>
                <form method="post" action="{{ route('server.settings.logo') }}" class="ap-inline-form"
                    data-confirm="{{ __('Restore the default logo? The custom logo files are deleted.') }}">
                    @csrf
                    <input type="hidden" name="action" value="restore">
                    <x-ap-button type="submit" icon="refresh" variant="ghost">{{ __('Restore default logo') }}</x-ap-button>
                </form>
            </div>
        @endif
    </x-ap-card>
</x-ap-page>
