{{-- Status badge: ok, warn, danger, info, accent or neutral. Always carries text. --}}
@props(['tone' => null, 'dot' => true, 'mono' => false])
<span {{ $attributes->merge(['class' => trim('ap-badge'.($tone ? ' is-'.$tone : '').($mono ? ' ap-badge-mono' : ''))]) }}>@if ($dot && $tone !== 'accent' && ! $mono)<span class="dot"></span>@endif{{ $slot }}</span>
