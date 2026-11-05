@php
    $tones = ['queued' => 'info', 'running' => 'warn', 'succeeded' => 'ok', 'failed' => 'danger', 'cancelled' => null];
    $labels = ['queued' => __('Queued'), 'running' => __('Running'), 'succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'cancelled' => __('Cancelled')];
@endphp
<x-ap-badge :tone="$tones[$status] ?? null">{{ $labels[$status] ?? $status }}</x-ap-badge>
