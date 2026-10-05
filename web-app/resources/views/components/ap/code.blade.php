{{-- Monospace block for commands, paths and configuration. --}}
@props(['label' => null])
<pre {{ $attributes->merge(['class' => 'ap-code']) }} @if ($label) aria-label="{{ $label }}" @endif tabindex="0">{{ $slot }}</pre>
