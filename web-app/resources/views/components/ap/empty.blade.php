{{-- Empty state with the arc artwork, a title, text and actions. --}}
@props(['icon' => 'inbox', 'title', 'actions' => null])
<div {{ $attributes->merge(['class' => 'ap-empty']) }}>
    <div class="ap-empty-art">
        <svg class="arcs" viewBox="0 0 88 56" aria-hidden="true"><path d="M4 56a40 40 0 0 1 80 0"></path><path d="M16 56a28 28 0 0 1 56 0"></path></svg>
        <x-ap-icon :name="$icon" />
    </div>
    <h3>{{ $title }}</h3>
    @if ($slot->isNotEmpty())<p>{{ $slot }}</p>@endif
    @if ($actions)<div class="ap-actions">{{ $actions }}</div>@endif
</div>
