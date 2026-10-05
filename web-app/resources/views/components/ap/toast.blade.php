{{-- Flash message as a toast (apToast): ok leaves after 6 s, error stays. --}}
@props(['type' => 'ok', 'title', 'message' => null])
@php
    $tone = ['ok' => 'ok', 'error' => 'danger', 'warn' => 'warn', 'info' => 'info'][$type] ?? 'info';
    $icon = ['ok' => 'check-circle', 'error' => 'x-circle', 'warn' => 'alert', 'info' => 'info'][$type] ?? 'info';
@endphp
<div {{ $attributes->merge(['class' => 'ap-toast is-'.$tone]) }} role="{{ $type === 'error' ? 'alert' : 'status' }}" x-data="apToast({ sticky: {{ $type === 'error' ? 'true' : 'false' }} })">
    <x-ap-icon :name="$icon" />
    <div class="ap-toast-body">
        <b>{{ $title }}</b>
        @if ($message)<span>{{ $message }}</span>@endif
        {{ $slot }}
    </div>
    <button type="button" class="ap-toast-close" aria-label="{{ __('Dismiss') }}" x-on:click="dismiss()" x-cloak><x-ap-icon name="x" size="sm" /></button>
</div>
