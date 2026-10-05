{{--
    Tab panels synced with the URL hash (apTabs). tabs: [name => label].
    Panels are <x-ap-tab-panel name="…"> in the slot. Without JavaScript the
    tabs are links to the stacked panels.
--}}
@props(['tabs' => [], 'label', 'initial' => null])
<div {{ $attributes->merge(['class' => 'ap-tabset']) }} x-data="apTabs({ initial: @js($initial ?? array_key_first($tabs)) })">
    <div class="ap-tabs" role="tablist" aria-label="{{ $label }}" x-on:click="onClick($event)" x-on:keydown="onKeydown($event)">
        @foreach ($tabs as $name => $tabLabel)
            <a class="ap-tab" role="tab" id="tab-{{ $name }}" data-tab="{{ $name }}" href="#{{ $name }}" aria-controls="{{ $name }}"
                aria-selected="{{ $name === ($initial ?? array_key_first($tabs)) ? 'true' : 'false' }}">{{ $tabLabel }}</a>
        @endforeach
    </div>
    {{ $slot }}
</div>
