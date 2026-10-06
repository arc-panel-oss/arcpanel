{{-- Server settings: Mail Server (web/edit/server, E7 batch 3 spec §8.2). --}}
@php
    $relay = ($config['SMTP_RELAY'] ?? '') === 'true';
    $editors = array_filter([
        $config['MAIL_SYSTEM'] ?? '' => __('Mail Server'),
        $config['IMAP_SYSTEM'] ?? '' => __('IMAP/POP3 Server'),
        $config['ANTIVIRUS_SYSTEM'] ?? '' => __('Antivirus'),
        $config['ANTISPAM_SYSTEM'] ?? '' => __('Antispam'),
    ], fn ($label, $service) => $service !== '', ARRAY_FILTER_USE_BOTH);
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Mail Server')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('Mail Server')" icon="mail">
        <dl class="ap-dl">
            @foreach ($editors as $service => $label)
                <dt>{{ $label }}</dt>
                <dd class="mono">
                    @if (Route::has('server.services.config') && ($editor = \App\Services\Server\ServiceConfigs::editorFor($service)))
                        <a href="{{ route('server.services.config', ['service' => $editor]) }}">{{ $service }}</a>
                    @else
                        {{ $service }}
                    @endif
                </dd>
            @endforeach
        </dl>

        <x-ap-form :action="route('server.settings.update.mail')" x-data="{ relay: {{ $relay ? 'true' : 'false' }} }">
            <fieldset class="ap-fieldset-plain" @disabled(! $canEdit)>
                @if (($config['WEBMAIL_SYSTEM'] ?? '') !== '')
                    <x-ap-input name="webmail_alias" :label="__('Webmail Alias')" :value="$config['WEBMAIL_ALIAS'] ?? ''" mono
                        :hint="__('Webmail answers on this subdomain of every mail domain.')" />
                @endif
                <x-ap-switch name="relay" :label="__('Global SMTP Relay')" :description="__('Send all outgoing mail of the server through another SMTP server.')" :checked="$relay" x-model="relay" />
                <div class="ap-form-grid" x-show="relay">
                    <x-ap-input name="relay_host" :label="__('Host')" :value="$config['SMTP_RELAY_HOST'] ?? ''" mono />
                    <x-ap-input name="relay_port" type="number" :label="__('Port')" :value="$config['SMTP_RELAY_PORT'] ?? '587'" mono />
                    <x-ap-input name="relay_user" :label="__('Username')" :value="$config['SMTP_RELAY_USER'] ?? ''" mono autocomplete="off" />
                    <x-ap-password name="relay_password" :label="__('Password')" :meter="false"
                        :hint="$relay ? __('Required when the host, port or username changes; leave empty to keep the current password.') : null" />
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
