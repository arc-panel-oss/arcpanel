{{--
    Dialog (apModal): focus trap, Esc, focus return. Open with
    x-on:click="$dispatch('ap-modal', { id: '…' })". drawer renders a side panel.
--}}
@props(['id', 'title', 'description' => null, 'icon' => null, 'footer' => null, 'drawer' => false])
<div x-data="apModal({ id: @js($id) })" x-on:keydown="open && onKeydown($event)">
    <div class="ap-overlay{{ $drawer ? ' ap-overlay-drawer' : '' }}" x-show="open" x-cloak x-on:click.self="close()">
        <div {{ $attributes->merge(['class' => $drawer ? 'ap-drawer' : 'ap-modal']) }} role="dialog" aria-modal="true" x-ref="dialog"
            aria-labelledby="{{ $id }}-title" @if ($description) aria-describedby="{{ $id }}-desc" @endif tabindex="-1">
            <div class="{{ $drawer ? 'ap-drawer-head' : 'ap-modal-head' }}">
                @if ($icon)<span class="ap-modal-icon"><x-ap-icon :name="$icon" /></span>@endif
                <div>
                    <h2 id="{{ $id }}-title">{{ $title }}</h2>
                    @if ($description)<p id="{{ $id }}-desc">{{ $description }}</p>@endif
                </div>
                @if ($drawer)
                    <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="x" :label="__('Close')" x-on:click="close()" />
                @endif
            </div>
            <div class="ap-modal-body">{{ $slot }}</div>
            @if ($footer)
                <div class="ap-modal-foot">{{ $footer }}</div>
            @endif
        </div>
    </div>
</div>
