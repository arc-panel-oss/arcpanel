{{-- Filter chip; with remove-href it renders a link that clears the filter. --}}
@props(['active' => false, 'removeHref' => null, 'removeLabel' => null])
<span {{ $attributes->merge(['class' => 'ap-chip'.($active ? ' is-on' : '')]) }}>{{ $slot }}@if ($removeHref)<a class="ap-chip-remove" href="{{ $removeHref }}" aria-label="{{ $removeLabel ?? __('Remove') }}"><x-ap-icon name="x" size="sm" /></a>@endif</span>
