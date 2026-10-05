{{-- Inline alert: info, ok, warn or danger; danger is announced (role=alert). --}}
@props(['tone' => 'info', 'title' => null, 'actions' => null])
@php
    $icon = ['info' => 'info', 'ok' => 'check-circle', 'warn' => 'alert', 'danger' => 'x-circle'][$tone] ?? 'info';
    $role = $tone === 'danger' ? 'alert' : ($tone === 'info' ? 'note' : 'status');
@endphp
<div {{ $attributes->merge(['class' => 'ap-alert is-'.$tone]) }} role="{{ $role }}">
    <x-ap-icon :name="$icon" />
    <div class="ap-alert-body">
        @if ($title)<b>{{ $title }}</b>@endif
        @if ($slot->isNotEmpty())<p>{{ $slot }}</p>@endif
    </div>
    @if ($actions)
        <div class="ap-actions">{{ $actions }}</div>
    @endif
</div>
