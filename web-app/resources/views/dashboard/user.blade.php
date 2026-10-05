{{-- User dashboard (docs/design/05 §4.4, E1-S2 spec §7). --}}
@php($usage = $widgets['usage'])
<x-ap-page :title="__('Dashboard')" :eyebrow="$usage->ok() && $usage->data['package'] !== '' ? __('Package').' · '.$usage->data['package'] : null"
    :heading="__('Welcome, :user', ['user' => $user])" :description="__('Your sites, mail and databases on this server.')">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('web.domains.create')" variant="primary" icon="plus">{{ __('Add Web Domain') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :title="__('Usage')" icon="chart">
        @if ($usage->ok())
            <div class="ap-usage-list">
                @foreach ($usage->data['items'] as $item)
                    <x-ap-usage-bar :label="$item['label']" :value="$item['value']" :limit="$item['limit'] === 'unlimited' ? null : (float) $item['limit']"
                        :value-label="$item['valueLabel']" :limit-label="$item['limitLabel']" :unlimited="$item['limit'] === 'unlimited'" />
                @endforeach
            </div>
        @else
            <x-ap-alert tone="danger">{{ $usage->error }}</x-ap-alert>
        @endif
    </x-ap-card>

    <div class="ap-split">
        <x-ap-card :title="__('Web domains')" icon="globe" :flush="true">
            <x-slot:actions><x-ap-button :href="route('web.domains.index')" variant="ghost" size="sm">{{ __('All web domains') }}<x-ap-icon name="chevron-right" /></x-ap-button></x-slot:actions>
            @php($domains = $widgets['domains'])
            @if (! $domains->ok())
                <div class="ap-card-body"><x-ap-alert tone="danger">{{ $domains->error }}</x-ap-alert></div>
            @elseif ($domains->data === [])
                <x-ap-empty icon="globe" :title="__('No web domains yet')">
                    @if ($canCreate)
                        <x-slot:actions><x-ap-button :href="route('web.domains.create')" variant="primary" icon="plus">{{ __('Add Web Domain') }}</x-ap-button></x-slot:actions>
                    @endif
                </x-ap-empty>
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table ap-table-cards">
                        <thead><tr><th scope="col">{{ __('Domain') }}</th><th scope="col">{{ __('SSL') }}</th><th scope="col" class="col-num">{{ __('Disk') }}</th></tr></thead>
                        <tbody>
                            @foreach ($domains->data as $row)
                                <tr>
                                    <td data-label="{{ __('Domain') }}"><a class="mono" href="{{ route('web.domains.edit', ['user' => $user, 'domain' => $row['domain']]) }}">{{ $row['domain'] }}</a>
                                        @if ($row['suspended'])<x-ap-badge tone="danger">{{ __('Suspended') }}</x-ap-badge>@endif</td>
                                    <td data-label="{{ __('SSL') }}">
                                        @if ($row['ssl'])
                                            <x-ap-badge tone="ok">{{ $row['letsEncrypt'] ? __('Let’s Encrypt') : __('Enabled') }}</x-ap-badge>
                                        @else
                                            <x-ap-badge>{{ __('Disabled') }}</x-ap-badge>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('Disk') }}" class="col-num mono">{{ \App\Services\Dashboard\DashboardService::size($row['diskMb']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>

        <div class="ap-stack">
            <x-ap-card :title="__('Last backup')" icon="archive">
                @php($backup = $widgets['backups'])
                @if (! $backup->ok())
                    <x-ap-alert tone="danger">{{ $backup->error }}</x-ap-alert>
                @elseif ($backup->data === null)
                    <p class="muted">{{ __('No backups yet.') }}</p>
                @else
                    <dl class="ap-dl">
                        <dt>{{ __('File') }}</dt><dd class="mono">{{ $backup->data['name'] }}</dd>
                        <dt>{{ __('Date') }}</dt><dd>{{ $backup->data['date'] }} {{ $backup->data['time'] }}</dd>
                        <dt>{{ __('Size') }}</dt><dd>{{ $backup->data['size'] }}</dd>
                    </dl>
                @endif
                <x-slot:footer><a href="{{ $backupsUrl }}">{{ __('All backups') }}</a></x-slot:footer>
            </x-ap-card>

            <x-ap-card :title="__('Notifications')" icon="bell" :flush="true">
                @php($notes = $widgets['notifications'])
                @if (! $notes->ok())
                    <div class="ap-card-body"><x-ap-alert tone="danger">{{ $notes->error }}</x-ap-alert></div>
                @elseif ($notes->data === [])
                    <x-ap-empty icon="bell" :title="__('You’re all caught up')" />
                @else
                    <ul class="ap-list">
                        @foreach ($notes->data as $note)
                            <li><div class="ap-list-main"><b>{{ $note['title'] }}</b><span>{{ $note['body'] }}</span></div><span class="subtle mono">{{ $note['date'] }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>
        </div>
    </div>
</x-ap-page>
