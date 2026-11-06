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
        {{-- The first and the last page and two on each side of the current one; a gap for the rest (a long audit log has hundreds of pages) --}}
        @php($shown = 0)
        @for ($p = 1; $p <= $pages; $p++)
            @continue($p !== 1 && $p !== $pages && abs($p - $page) > 2)
            @if ($p > $shown + 1)<span class="ap-pager-gap" aria-hidden="true">…</span>@endif
            @if ($p === $page)<span aria-current="page">{{ $p }}</span>@else<a href="{{ $link($p) }}">{{ $p }}</a>@endif
            @php($shown = $p)
        @endfor
        @if ($page < $pages)<a href="{{ $link($page + 1) }}" aria-label="{{ __('Next page') }}"><x-ap-icon name="chevron-right" size="sm" /></a>@endif
    </nav>
@endif
