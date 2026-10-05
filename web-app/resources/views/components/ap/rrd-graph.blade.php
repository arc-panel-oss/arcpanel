{{-- Server graph from v-export-rrd (apRrdGraph, chart.js on demand) with a period selector. --}}
@props(['url', 'service' => 'la', 'title', 'period' => 'daily'])
@php($periods = ['daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly'), 'yearly' => __('Yearly')])
<div {{ $attributes->merge(['class' => 'ap-rrd']) }} x-data="apRrdGraph({ url: @js($url), service: @js($service), period: @js($period) })">
    <x-ap-card :title="$title" icon="activity">
        <x-slot:actions>
            <div class="ap-segment" role="group" aria-label="{{ __('Period') }}" x-cloak>
                @foreach ($periods as $value => $label)
                    <button type="button" x-on:click="setPeriod('{{ $value }}')" x-bind:aria-pressed="(period === '{{ $value }}').toString()">{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>
        <div class="ap-rrd-canvas">
            <canvas x-ref="canvas" role="img" aria-label="{{ $title }}"></canvas>
        </div>
        <p class="ap-hint" x-show="loading" x-cloak>{{ __('Loading') }}</p>
        <p class="ap-error" x-show="error" x-text="error" x-cloak role="alert"></p>
        <noscript><p class="ap-hint">{{ __('Graphs need JavaScript.') }}</p></noscript>
    </x-ap-card>
</div>
