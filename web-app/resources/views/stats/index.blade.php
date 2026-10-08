{{--
    Statistics (web/list/stats, E7 batch 2 spec §9.4): the newest month in
    detail (quotas, where the disk goes, resource counters compared with the
    month before), then every month as one row of the history table.
--}}
@php
    $overview = new \App\View\StatsOverview($months, $scope, $isAdmin);
    $latest = $overview->latest();
    $previous = $overview->previous();
    $description = match ($scope) {
        'server' => __('Totals of every account on this server, per month.'),
        'tree' => __('Totals of your account and your customers, per month.'),
        default => __('Usage of :user, per month.', ['user' => $owner]),
    };
    // Short on the cards ("vs Sep"); the head names the month in full
    $since = $previous ? __('vs :month', ['month' => $previous->month() ? __($previous->month()->format('M')) : $previous->date]) : null;
@endphp
<x-ap-page :title="__('Statistics')" :crumbs="[['label' => __('Account')], ['label' => __('Statistics')]]" :description="$description">
    <x-ap-owner-switch route="stats.index" :owners="$owners" :owner="$owner" :label="__('Show statistics')" :empty="__('Overall Statistics')" />

    @if ($latest === null)
        <x-ap-card>
            <x-ap-empty icon="trend" :title="__('No statistics yet')">{{ __('Statistics are collected once a day.') }}</x-ap-empty>
        </x-ap-card>
    @else
        <section class="ap-stats" aria-labelledby="stats-latest">
            <div class="ap-stats-head">
                <h2 id="stats-latest" class="ap-stats-month"><x-ap-icon name="calendar" />{{ \App\View\StatsOverview::title($latest) }}</h2>
                <span class="muted">{{ $previous ? __('Compared with :month', ['month' => \App\View\StatsOverview::title($previous)]) : __('Statistics are collected once a day.') }}</span>
            </div>

            <div class="ap-stats-quotas">
                @foreach ($overview->quotas() as $quota)
                    <x-ap-card class="ap-stats-quota" :title="__($quota['label'])" :icon="$quota['icon']" :level="3">
                        <div class="ap-stats-figure">
                            <span class="ap-stats-value">{{ $quota['valueLabel'] }}</span>
                            <span class="muted">{{ $quota['limit'] !== null ? __('of :limit', ['limit' => $quota['limitLabel']]) : __('No limit') }}</span>
                        </div>
                        @if ($quota['limit'] !== null)
                            {{-- Warn from 80 %, danger from 95 %, as x-ap-usage-bar --}}
                            <div @class(['ap-usage', 'ap-stats-bar', 'is-warn' => $quota['percent'] >= 80 && $quota['percent'] < 95, 'is-danger' => $quota['percent'] >= 95])>
                                <div class="ap-bar" role="meter" aria-label="{{ __($quota['label']) }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $quota['percent'] }}"
                                    aria-valuetext="{{ $quota['valueLabel'] }} / {{ $quota['limitLabel'] }}"><span style="width: {{ $quota['percent'] }}%"></span></div>
                                <span class="ap-usage-note">{{ __(':percent % used', ['percent' => $quota['percent']]) }}</span>
                            </div>
                        @endif
                        @if ($quota['delta'] !== null)
                            <span class="ap-stats-delta">{{ $quota['delta'] }} {{ $since }}</span>
                        @endif
                        @php($trend = $overview->trend($quota['key']))
                        @if ($trend !== [])
                            {{-- One series over the months (oldest first); the History table holds the same values --}}
                            <div class="ap-sparkbars" aria-hidden="true">
                                @foreach ($trend as $point)
                                    <span @class(['is-latest' => $point['latest']]) title="{{ $point['title'] }}: {{ $point['valueLabel'] }}"><i style="height: {{ max($point['percent'], 2) }}%"></i></span>
                                @endforeach
                            </div>
                        @endif
                    </x-ap-card>
                @endforeach

                <x-ap-card class="ap-stats-disk" :title="__('Disk usage')" icon="layers" :level="3">
                    @php($parts = $overview->diskParts())
                    @if ($parts === [])
                        <p class="muted">{{ __('No disk space is used yet.') }}</p>
                    @else
                        {{-- Part to whole: one stacked bar, read through the legend (values and shares) --}}
                        <div class="ap-stack-bar" aria-hidden="true">
                            @foreach ($parts as $index => $part)
                                @if ($part['percent'] > 0)
                                    <span class="is-s{{ $index + 1 }}" style="flex-grow: {{ $part['percent'] }}" title="{{ __($part['label']) }}: {{ $part['valueLabel'] }}"></span>
                                @endif
                            @endforeach
                        </div>
                        <ul class="ap-stack-legend">
                            @foreach ($parts as $index => $part)
                                <li>
                                    <span class="ap-swatch is-s{{ $index + 1 }}" aria-hidden="true"></span>
                                    <span class="ap-stack-label">{{ __($part['label']) }}</span>
                                    <span class="mono">{{ $part['valueLabel'] }}</span>
                                    <span class="muted mono ap-stack-share">{{ rtrim(rtrim(number_format($part['percent'], 1), '0'), '.') }} %</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ap-card>
            </div>

            <ul class="ap-stats-tiles" aria-label="{{ __('Resources') }}">
                @foreach ($overview->tiles() as $tile)
                    <li class="ap-stats-tile">
                        <span class="ap-stats-tile-icon" aria-hidden="true"><x-ap-icon :name="$tile['icon']" /></span>
                        <span class="ap-stats-tile-label">{{ __($tile['label']) }}</span>
                        <span class="ap-stats-tile-value">{{ $tile['value'] }}</span>
                        {{-- Kept when empty, so the change lines of all tiles share a row --}}
                        <span class="ap-stats-tile-meta muted">{{ $tile['meta'] }}</span>
                        @if ($tile['delta'] !== null)
                            <span class="ap-stats-delta">
                                @if ($tile['delta'] === 0)
                                    {{ __('No change') }}
                                @else
                                    <x-ap-icon name="arrow-up" size="sm" :class="$tile['delta'] < 0 ? 'is-down' : null" />{{ ($tile['delta'] > 0 ? '+' : '−').abs($tile['delta']) }} {{ $since }}
                                @endif
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        @if (count($months) > 1)
            @php($columns = $overview->columns())
            <x-ap-card :title="__('History')" icon="history" :flush="true">
                <x-slot:actions><span class="muted">{{ trans_choice(':count month|:count months', count($months), ['count' => count($months)]) }}</span></x-slot:actions>
                <div class="ap-table-wrap">
                    <table class="ap-table ap-table-cards ap-stats-history">
                        <caption class="sr-only">{{ __('Statistics') }}, {{ trans_choice(':count month|:count months', count($months), ['count' => count($months)]) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Month') }}</th>
                                @foreach ($columns as [$label])
                                    <th scope="col" class="col-num">{{ __($label) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($months as $month)
                                <tr>
                                    <th scope="row" data-label="{{ __('Month') }}">{{ \App\View\StatsOverview::title($month) }}</th>
                                    @foreach ($columns as $key => [$label, $kind])
                                        <td class="col-num mono" data-label="{{ __($label) }}">{{ $overview->cell($month, $key, $kind) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ap-card>
        @endif
    @endif
</x-ap-page>
