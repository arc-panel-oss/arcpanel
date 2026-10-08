{{--
    A number or "unlimited" (unlimitedInput.js). Posts NAME and, when
    ticked, NAME_unlimited=1; the server treats the latter as "unlimited".
--}}
@props(['name', 'label', 'value' => null, 'id' => null, 'hint' => null, 'bag' => null, 'suffix' => null])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $current = (string) \App\View\FormField::old($name, $value);
    $unlimited = \App\View\FormField::submitted()
        ? \App\View\FormField::old($name.'_unlimited') !== null
        : $current === 'unlimited';
    $describedBy = \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]);
@endphp
<x-ap-field :label="$label" :for="$id" :hint="$hint" :error="$error" x-data="apUnlimited({ unlimited: {{ $unlimited ? 'true' : 'false' }} })">
    <div class="ap-unlimited">
        <div class="ap-input-group">
            <input {{ $attributes->merge(['class' => 'ap-input']) }} x-ref="input" id="{{ $id }}" name="{{ $name }}" type="number" min="0"
                value="{{ $unlimited ? '' : $current }}" @disabled($unlimited)
                @if ($error) aria-invalid="true" @endif
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
            @if ($suffix)<span class="ap-addon" title="{{ $suffix }}"><span class="ap-addon-text">{{ $suffix }}</span></span>@endif
        </div>
        <label class="ap-check"><input type="checkbox" name="{{ $name }}_unlimited" value="1" @checked($unlimited) x-on:change="toggle()"><span>{{ __('Unlimited') }}</span></label>
    </div>
</x-ap-field>
