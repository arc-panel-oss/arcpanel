{{-- Tabs of the server settings, one page each (E7 batch 3 spec §8.1). --}}
@php
    $labels = [
        'general' => __('General'), 'web' => __('Web Server'), 'dns' => __('DNS Server'), 'mail' => __('Mail Server'),
        'databases' => __('Databases'), 'backups' => __('Backups'), 'ssl' => __('SSL'), 'security' => __('Security'),
        'plugins' => __('Plugins'), 'white-label' => __('White Label'),
    ];
@endphp
<nav class="ap-tabs" aria-label="{{ __('Server settings') }}">
    @foreach ($tabs as $name)
        <a class="ap-tab" href="{{ route('server.settings.tab', ['tab' => $name]) }}" @if ($name === $tab) aria-current="page" @endif>{{ $labels[$name] }}</a>
    @endforeach
</nav>
@unless ($canEdit)
    <x-ap-alert tone="info" :title="__('Read-only')">{{ __('You can view these settings but not change them.') }}</x-ap-alert>
@endunless
