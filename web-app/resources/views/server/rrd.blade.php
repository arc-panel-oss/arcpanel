{{-- Task Monitor: every server graph (web/list/rrd, E7 batch 2 spec §9.5). --}}
@php
    $labels = ['daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly'), 'yearly' => __('Yearly'),
        'biennially' => __('Biennially'), 'triennially' => __('Triennially')];
@endphp
<x-ap-page :title="__('Task Monitor')" :crumbs="[['label' => __('Server')], ['label' => __('Task Monitor')]]"
    :description="__('Load, memory, traffic and service graphs of this server.')">
    <x-slot:actions>
        <nav class="ap-segment" aria-label="{{ __('Period') }}">
            @foreach ($periods as $value)
                <a href="{{ route('server.rrd', ['period' => $value]) }}" @if ($value === $period) aria-current="page" @endif>{{ $labels[$value] }}</a>
            @endforeach
        </nav>
    </x-slot:actions>

    @if ($graphs === [])
        <x-ap-card>
            <x-ap-empty icon="chart" :title="__('No graphs yet')">{{ __('Graphs appear once the server has collected data.') }}</x-ap-empty>
        </x-ap-card>
    @else
        <div class="ap-grid ap-grid-2">
            @foreach ($graphs as $graph)
                <x-ap-rrd-graph :url="route('shell.rrd')" :service="$graph['service']" :title="__($graph['title'])" :period="$period" />
            @endforeach
        </div>
    @endif
</x-ap-page>
