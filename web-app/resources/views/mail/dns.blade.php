{{-- DNS records a mail domain needs (web/list/mail?domain=&dns=1, templates/pages/list_mail_dns.php). --}}
@php
    $params = ['user' => $record->user, 'domain' => $record->domain];
@endphp
<x-ap-page :title="__('DNS Records')" :crumbs="[['label' => __('Mail'), 'url' => route('mail.index')], ['label' => $record->domain, 'url' => route('mail.accounts.index', $params)], ['label' => __('DNS Records')]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="dns" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $record->user }} · {{ __('DNS Records') }}</span>
                <h1 class="mono">{{ $record->domain }}</h1>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('mail.index')" icon="return">{{ __('Back') }}</x-ap-button>
        @if ($zoneUrl)
            <x-ap-button :href="$zoneUrl" variant="primary" icon="dns">{{ __('Open DNS zone') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-alert>{{ __('Add these records to the DNS zone of the domain so that other servers deliver mail to this server and accept its mail.') }}</x-ap-alert>

    <x-ap-card :flush="true">
        <div class="ap-table-wrap">
            <table class="ap-table ap-table-cards ap-mail-dns">
                <caption class="sr-only">{{ __('DNS Records') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('Record') }}</th>
                        <th scope="col">{{ __('Type') }}</th>
                        <th scope="col">{{ __('Priority') }}</th>
                        <th scope="col">{{ __('TTL') }}</th>
                        <th scope="col">{{ __('IP or Value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $index => $row)
                        @php $id = 'mail-dns-'.$index; @endphp
                        <tr x-data="{ copied: '' }">
                            <td data-label="{{ __('Record') }}">
                                <div class="ap-input-group">
                                    <input class="ap-input ap-input-mono" id="{{ $id }}-record" type="text" value="{{ $row['record'] }}" readonly
                                        aria-label="{{ __('Record') }} {{ $index + 1 }}" x-ref="record">
                                    <button type="button" class="ap-btn ap-btn-sm" title="{{ __('Copy') }}" aria-label="{{ __('Copy') }}: {{ $row['record'] }}"
                                        x-on:click="navigator.clipboard?.writeText($refs.record.value).then(() => { copied = 'record'; setTimeout(() => copied = '', 1500) })">
                                        <x-ap-icon name="copy" /><span x-text="copied === 'record' ? @js(__('Copied')) : ''"></span>
                                    </button>
                                </div>
                            </td>
                            <td data-label="{{ __('Type') }}"><x-ap-badge :mono="true">{{ $row['type'] }}</x-ap-badge></td>
                            <td data-label="{{ __('Priority') }}" class="mono">{{ $row['priority'] }}</td>
                            <td data-label="{{ __('TTL') }}" class="mono">{{ $row['ttl'] }}</td>
                            <td data-label="{{ __('IP or Value') }}">
                                <div class="ap-input-group">
                                    <input class="ap-input ap-input-mono" id="{{ $id }}-value" type="text" value="{{ $row['value'] }}" readonly
                                        aria-label="{{ __('IP or Value') }} {{ $index + 1 }}" x-ref="value">
                                    <button type="button" class="ap-btn ap-btn-sm" title="{{ __('Copy') }}" aria-label="{{ __('Copy') }}: {{ $row['type'] }} {{ $row['record'] }}"
                                        x-on:click="navigator.clipboard?.writeText($refs.value.value).then(() => { copied = 'value'; setTimeout(() => copied = '', 1500) })">
                                        <x-ap-icon name="copy" /><span x-text="copied === 'value' ? @js(__('Copied')) : ''"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ap-card>
</x-ap-page>
