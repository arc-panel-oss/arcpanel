{{--
    Client configuration of a mail account (templates/includes/email-settings-panel.php).
    Lives inside x-data="apMailAccount(…)", which mirrors the account name
    and the password typed in the form.
--}}
@php
    $fields = array_filter([
        'username' => [__('Username'), 'username', null],
        'password' => [__('Password'), 'password', null],
        'webmail' => $client['webmail'] !== null ? [__('Webmail'), null, $client['webmail']] : null,
        'hostname' => [__('Hostname'), null, $client['hostname']],
    ]);
    $ports = [
        __('IMAP Settings') => ['SSL/TLS' => 993, 'STARTTLS' => 143, __('No encryption') => 143],
        __('POP3 Settings') => ['SSL/TLS' => 995, 'STARTTLS' => 110, __('No encryption') => 110],
        __('SMTP Settings') => ['SSL/TLS' => 465, 'STARTTLS' => 587, __('No encryption') => 25],
    ];
@endphp
<x-ap-card :title="__('Common Account Settings')" icon="info" class="ap-mail-client">
    <p class="muted">{{ __('Connect to this account using credentials:') }}</p>
    <div class="ap-stack">
        @foreach ($fields as $key => [$label, $model, $value])
            @php $id = 'mail-client-'.$key; @endphp
            <x-ap-field :label="$label" :for="$id">
                <div class="ap-input-group">
                    <input class="ap-input ap-input-mono" id="{{ $id }}" type="text" readonly
                        @if ($model) x-bind:value="{{ $model }}" @endif
                        @if ($value !== null) value="{{ $value }}" @endif
                        @if ($key === 'password') placeholder="{{ __('As entered above') }}" @endif>
                    <button type="button" class="ap-btn ap-btn-sm" title="{{ __('Copy to clipboard') }}" aria-label="{{ __('Copy to clipboard') }}: {{ $label }}"
                        x-on:click="copy(@js($key), document.getElementById(@js($id)).value)">
                        <x-ap-icon name="copy" /><span x-text="copied === @js($key) ? @js(__('Copied')) : ''"></span>
                    </button>
                </div>
            </x-ap-field>
        @endforeach
    </div>
    @foreach ($ports as $title => $rows)
        <h3 class="ap-mail-client-title">{{ $title }}</h3>
        <dl class="ap-dl">
            <dt>{{ __('Authentication') }}</dt><dd>{{ __('Normal password') }}</dd>
            @foreach ($rows as $label => $port)
                <dt>{{ $label }}</dt><dd>{{ __('Port') }} {{ $port }}</dd>
            @endforeach
        </dl>
    @endforeach
</x-ap-card>
