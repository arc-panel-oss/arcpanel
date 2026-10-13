{{--
    A page in the shell: <title>, breadcrumb, page head (eyebrow, title,
    description, header actions) and the main content. Flash toasts and the
    main landmark come from x-ap-shell.
--}}
@props(['title', 'heading' => null, 'eyebrow' => null, 'description' => null, 'crumbs' => null, 'actions' => null, 'head' => null])
@php
    $crumbs ??= [['label' => $title]];
@endphp
<x-ap-shell :title="$title" :crumbs="$crumbs">
    <div class="ap-page-head">
        @if ($head)
            {{ $head }}
        @else
            <div class="ap-page-head-text">
                @if ($eyebrow)<span class="eyebrow">{{ $eyebrow }}</span>@endif
                <h1>{{ $heading ?? $title }}</h1>
                @if ($description)<p>{{ $description }}</p>@endif
            </div>
        @endif
        @if ($actions)
            <div class="ap-actions">{{ $actions }}</div>
        @endif
    </div>
    @if (! empty($hubTabs))
        {{-- The pages of a sidebar hub (PageTabsComposer) --}}
        <nav class="ap-tabs ap-hub-tabs" aria-label="{{ $hubTabs['label'] }}">
            @foreach ($hubTabs['items'] as $tab)
                <a class="ap-tab" href="{{ $tab['url'] }}"@if ($tab['current']) aria-current="page"@endif>{{ $tab['label'] }}</a>
            @endforeach
        </nav>
    @endif
    {{ $slot }}
</x-ap-shell>
