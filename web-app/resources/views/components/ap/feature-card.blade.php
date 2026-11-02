{{--
    Card of a package feature (Node.js, Docker apps …) with its switch in the
    head. While the switch is off the settings below are greyed out and out of
    reach (inert); they still post, so their values are kept. note says why
    the switch cannot be turned on (not in the reseller plan). The id of the
    card names the settings block: ID-settings.
--}}
@props(['title', 'icon' => null, 'name', 'description' => null, 'checked' => false, 'disabled' => false, 'note' => null, 'bag' => null])
@php
    $switchId = \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $on = \App\View\FormField::submitted()
        ? \App\View\FormField::old($name) !== null
        : (bool) $checked;
    $describedBy = \App\View\FormField::describedBy([$description ? $switchId.'-hint' : null, $note ? $switchId.'-note' : null, $error ? $switchId.'-error' : null]);
    $settingsId = $attributes->get('id') ? $attributes->get('id').'-settings' : null;
@endphp
@php($cardAttributes = $attributes->merge(['class' => 'ap-feature', 'x-data' => '{ on: '.($on ? 'true' : 'false').' }']))
<x-ap-card :title="$title" :icon="$icon" :attributes="$cardAttributes">
    <x-slot:actions>
        <label class="ap-switch ap-feature-switch" for="{{ $switchId }}">
            <span class="ap-feature-state" x-text="on ? @js(__('On')) : @js(__('Off'))">{{ $on ? __('On') : __('Off') }}</span>
            <input type="checkbox" role="switch" id="{{ $switchId }}" name="{{ $name }}" value="1" x-model="on" @checked($on) @disabled($disabled)
                aria-label="{{ $title }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
        </label>
    </x-slot:actions>
    @if ($description)
        <p class="ap-feature-text" id="{{ $switchId }}-hint">{{ $description }}</p>
    @endif
    @if ($note)
        <x-ap-alert tone="info" id="{{ $switchId }}-note">{{ $note }}</x-ap-alert>
    @endif
    @if ($error)
        <p class="ap-error" id="{{ $switchId }}-error" role="alert"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</p>
    @endif
    <div class="ap-feature-body{{ $on ? '' : ' is-off' }}" @if ($settingsId) id="{{ $settingsId }}" @endif @unless ($on) inert @endunless
        :class="{ 'is-off': ! on }" :inert="! on">
        {{ $slot }}
    </div>
</x-ap-card>
