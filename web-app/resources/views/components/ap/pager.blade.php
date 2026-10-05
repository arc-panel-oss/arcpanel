{{-- Server paging for long lists: "first–last of total" and page links. --}}
@props(['page' => 1, 'pages' => 1, 'total' => 0, 'perPage' => 500, 'url'])
@php
    $first = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
    $last = min($total, $page * $perPage);
    $link = fn (int $p) => $url.(str_contains($url, '?') ? '&' : '?').'page='.$p;
@endphp
<span>{{ $first }}–{{ $last }} {{ __('of') }} {{ $total }}</span>
@if ($pages > 1)
    <nav class="ap-pager" aria-label="{{ __('Pagination') }}">
        @if ($page > 1)<a href="{{ $link($page - 1) }}" aria-label="{{ __('Previous page') }}"><x-ap-icon name="chevron-left" size="sm" /></a>@endif
        @for ($p = 1; $p <= $pages; $p++)
            @if ($p === $page)<span aria-current="page">{{ $p }}</span>@else<a href="{{ $link($p) }}">{{ $p }}</a>@endif
        @endfor
        @if ($page < $pages)<a href="{{ $link($page + 1) }}" aria-label="{{ __('Next page') }}"><x-ap-icon name="chevron-right" size="sm" /></a>@endif
    </nav>
@endif
