{{--
    Cards as a radio group (apCardPicker): template and app selection
    (docs/design/09, 08). options: [value => [title, description?, logo?,
    category?, badge?, meta?]]; categories: [key => label] adds filter chips
    for the categories that have options; meta is shown in small print.
--}}
@props(['name', 'label', 'options' => [], 'value' => null, 'searchable' => false, 'categories' => [], 'bag' => null])
@php
    $current = (string) \App\View\FormField::old($name, $value);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $used = array_unique(array_filter(array_map(fn ($option) => $option['category'] ?? null, $options)));
    $chips = array_intersect_key($categories, array_flip($used));
@endphp
<fieldset {{ $attributes->merge(['class' => 'ap-field ap-fieldset']) }} x-data="apCardPicker({ value: @js($current) })">
    <legend class="ap-label">{{ $label }}</legend>
    @if ($searchable || count($chips) > 1)
        <div class="ap-picker-tools" x-cloak>
            @if ($searchable)
                <div class="ap-input-affix"><x-ap-icon name="search" size="sm" /><input class="ap-input" type="search" x-model="query" aria-label="{{ __('Search') }}"></div>
            @endif
            @if (count($chips) > 1)
                <div class="ap-row ap-picker-chips" role="group" aria-label="{{ __('Category') }}">
                    <button type="button" class="ap-chip" :class="{ 'is-on': category === '' }" :aria-pressed="category === ''" @click="category = ''">{{ __('All') }}</button>
                    @foreach ($chips as $key => $chipLabel)
                        <button type="button" class="ap-chip" :class="{ 'is-on': category === @js($key) }" :aria-pressed="category === @js($key)" @click="category = @js($key)">{{ $chipLabel }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
    <div class="ap-picker">
        @foreach ($options as $optionValue => $option)
            <label class="ap-pick" data-card data-category="{{ $option['category'] ?? '' }}"
                data-search="{{ mb_strtolower($optionValue.' '.($option['title'] ?? '').' '.($option['description'] ?? '')) }}">
                <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) $optionValue === $current)>
                <span class="ap-pick-logo">{{ $option['logo'] ?? strtoupper(substr((string) ($option['title'] ?? $optionValue), 0, 2)) }}</span>
                <b>{{ $option['title'] ?? $optionValue }}</b>
                @if ($option['description'] ?? null)<span>{{ $option['description'] }}</span>@endif
                @if (($option['badge'] ?? null) || ($option['meta'] ?? null))
                    <span class="ap-pick-foot">
                        @if ($option['badge'] ?? null)<x-ap-badge>{{ $option['badge'] }}</x-ap-badge>@endif
                        @if ($option['meta'] ?? null)<small class="mono">{{ $option['meta'] }}</small>@endif
                    </span>
                @endif
            </label>
        @endforeach
    </div>
    <span class="ap-hint" x-show="empty" x-cloak>{{ __('Nothing matches the search.') }}</span>
    @if ($error)<span class="ap-error" role="alert"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>@endif
</fieldset>
