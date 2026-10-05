{{--
    Dashboard resource tile: a card with a semicircle gauge, the value and
    meta lines. percent drives the gauge and the warn (80) / danger (95) tone.
--}}
@props(['title', 'icon' => null, 'percent' => 0, 'value', 'unit' => null, 'meta' => [], 'badge' => null])
@php
    $percent = max(0, min(100, (int) round((float) $percent)));
    $tone = $percent >= 95 ? 'danger' : ($percent >= 80 ? 'warn' : null);
@endphp
<x-ap-card :title="$title" :icon="$icon" {{ $attributes }}>
    @if ($badge || $tone)
        <x-slot:actions><x-ap-badge :tone="$tone ?? 'info'" :dot="false">{{ $badge ?? ($tone === 'danger' ? __('Critical') : __('High')) }}</x-ap-badge></x-slot:actions>
    @endif
    <div class="ap-gauge{{ $tone ? ' is-'.$tone : '' }}">
        <svg viewBox="0 0 112 64" role="meter" aria-label="{{ $title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
            <path class="g-tick" d="M1 58 A55 55 0 0 1 111 58"></path>
            <path class="g-track" d="M10 58 A46 46 0 0 1 102 58"></path>
            <path class="g-val" d="M10 58 A46 46 0 0 1 102 58" pathLength="100" style="stroke-dasharray: {{ $percent }} 100"></path>
        </svg>
        <div class="ap-stat">
            <div class="ap-gauge-value">{{ $value }}@if ($unit)<small>{{ $unit }}</small>@endif</div>
            @if ($meta)
                <div class="ap-stat-meta">@foreach ($meta as $line)<span>{{ $line }}</span>@endforeach</div>
            @endif
        </div>
    </div>
</x-ap-card>
