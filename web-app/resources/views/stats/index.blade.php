{{-- Statistics (web/list/stats, E7 batch 2 spec §9.4): one card per month, newest first. --}}
@php
    $size = fn (string $mb) => \App\Services\Dashboard\DashboardService::size((float) $mb);
    $description = match ($scope) {
        'server' => __('Totals of every account on this server, per month.'),
        'tree' => __('Totals of your account and your customers, per month.'),
        default => __('Usage of :user, per month.', ['user' => $owner]),
    };
@endphp
<x-ap-page :title="__('Statistics')" :crumbs="[['label' => __('Account')], ['label' => __('Statistics')]]" :description="$description">
    <x-ap-owner-switch route="stats.index" :owners="$owners" :owner="$owner" :label="__('Show statistics')" :empty="__('Overall Statistics')" />

    @if ($months === [])
        <x-ap-card>
            <x-ap-empty icon="trend" :title="__('No statistics yet')">{{ __('Statistics are collected once a day.') }}</x-ap-empty>
        </x-ap-card>
    @else
        <div class="ap-grid ap-grid-2">
            @foreach ($months as $month)
                @php
                    $title = $month->month() ? __($month->month()->format('M')).' '.$month->month()->format('Y') : $month->date;
                    $diskLimit = $month->limit('DISK_QUOTA');
                    $bandwidthLimit = $month->limit('BANDWIDTH');
                @endphp
                <x-ap-card :title="$title" icon="calendar">
                    <x-ap-usage-bar :label="__('Bandwidth')" :value="(float) $month->value('U_BANDWIDTH')" :limit="$bandwidthLimit" :unlimited="$bandwidthLimit === null"
                        :value-label="$size($month->value('U_BANDWIDTH'))" :limit-label="$bandwidthLimit !== null ? $size((string) $bandwidthLimit) : null" />
                    <x-ap-usage-bar :label="__('Disk')" :value="(float) $month->value('U_DISK')" :limit="$diskLimit" :unlimited="$diskLimit === null"
                        :value-label="$size($month->value('U_DISK'))" :limit-label="$diskLimit !== null ? $size((string) $diskLimit) : null" />
                    <dl class="ap-dl">
                        <dt>{{ __('Web') }}</dt><dd>{{ $size($month->value('U_DISK_WEB')) }}</dd>
                        <dt>{{ __('Databases') }}</dt><dd>{{ $size($month->value('U_DISK_DB')) }}</dd>
                        <dt>{{ __('Mail') }}</dt><dd>{{ $size($month->value('U_DISK_MAIL')) }}</dd>
                        <dt>{{ __('User Directory') }}</dt><dd>{{ $size($month->value('U_DISK_DIRS')) }}</dd>
                        @if ($isAdmin || $month->value('IP_OWNED') !== '0')
                            <dt>{{ __('IP Addresses') }}</dt><dd>{{ $month->value('IP_OWNED') }}</dd>
                        @endif
                        @if ($scope !== 'user' && isset($month->values['U_USERS']))
                            <dt>{{ __('Users') }}</dt><dd>{{ $month->value('U_USERS') }}</dd>
                        @endif
                        @foreach (\App\Services\Stats\StatsMonth::COUNTERS as $key => $label)
                            <dt>{{ __($label) }}</dt><dd>{{ $month->value($key) }}</dd>
                        @endforeach
                    </dl>
                </x-ap-card>
            @endforeach
        </div>
        <p class="muted">{{ trans_choice(':count month|:count months', count($months), ['count' => count($months)]) }}</p>
    @endif
</x-ap-page>
