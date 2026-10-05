{{--
    Cards as a radio group (apCardPicker): template and app selection
    (docs/design/09, 08). options: [value => [title, description?, logo?]].
--}}
@props(['name', 'label', 'options' => [], 'value' => null, 'searchable' => false])
@php($current = (string) \App\View\FormField::old($name, $value))
<fieldset {{ $attributes->merge(['class' => 'ap-field ap-fieldset']) }} x-data="apCardPicker({ value: @js($current) })">
    <legend class="ap-label">{{ $label }}</legend>
    @if ($searchable)
        <div class="ap-input-affix" x-cloak><x-ap-icon name="search" size="sm" /><input class="ap-input" type="search" x-model="query" aria-label="{{ __('Search') }}"></div>
    @endif
    <div class="ap-picker">
        @foreach ($options as $optionValue => $option)
            <label class="ap-pick" data-card data-search="{{ strtolower($optionValue.' '.($option['title'] ?? '')) }}">
                <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) $optionValue === $current)>
                <span class="ap-pick-logo">{{ $option['logo'] ?? strtoupper(substr((string) $optionValue, 0, 2)) }}</span>
                <b>{{ $option['title'] ?? $optionValue }}</b>
                @if ($option['description'] ?? null)<span>{{ $option['description'] }}</span>@endif
            </label>
        @endforeach
    </div>
</fieldset>
