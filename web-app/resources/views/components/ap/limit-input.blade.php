{{--
    A limit as an amount with a unit, or unlimited (apLimit). units: [unit =>
    label], posted with the amount: one is shown as an addon, more as a select;
    suffix is an addon that is not posted (seconds, CPUs). Only the hidden
    field posts, NAME as the server reads it (512M, 1.5, unlimited). bare is
    the unit a number without one means; a unit of the value that is not in
    the list is kept as an option.
--}}
@props(['name', 'label', 'value' => null, 'id' => null, 'hint' => null, 'bag' => null, 'units' => [], 'bare' => '', 'suffix' => null, 'unlimited' => true])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $current = trim((string) \App\View\FormField::old($name, $value));
    $isUnlimited = $current === 'unlimited';
    $amount = $current;
    $unit = array_key_first($units) ?? '';
    if ($isUnlimited) {
        $amount = '';
    } elseif (preg_match('/^(\d+(?:\.\d+)?)([A-Za-z%]*)$/D', $current, $parts) === 1) {
        [, $amount, $unit] = $parts;
        $unit = $unit !== '' ? $unit : $bare;
        if ($units !== [] && ! array_key_exists($unit, $units)) {
            $units[$unit] = $unit === '' ? '—' : $unit;
        }
    }
    $describedBy = \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]);
    $state = \Illuminate\Support\Js::from(['value' => $current, 'units' => array_map('strval', array_keys($units)), 'bare' => $bare]);
@endphp
<x-ap-field :label="$label" :for="$id" :hint="$hint" :error="$error" x-data="apLimit({{ $state }})">
    <input type="hidden" name="{{ $name }}" value="{{ $current }}" :value="value">
    <div class="ap-unlimited">
        <div class="ap-input-group">
            <input {{ $attributes->merge(['class' => 'ap-input ap-input-mono']) }} id="{{ $id }}" type="text" inputmode="decimal" autocomplete="off"
                value="{{ $amount }}" x-model="amount" :disabled="unlimited" :placeholder="unlimited ? '∞' : ''" @disabled($isUnlimited) @if ($isUnlimited) placeholder="∞" @endif
                @if ($error) aria-invalid="true" @endif
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
            @if (count($units) > 1)
                <div class="ap-select-wrap ap-unit">
                    <select class="ap-select" x-model="unit" :disabled="unlimited" @disabled($isUnlimited) aria-label="{{ sprintf(__('Unit of %s'), $label) }}">
                        @foreach ($units as $key => $unitLabel)
                            <option value="{{ $key }}" @selected((string) $key === $unit)>{{ $unitLabel }}</option>
                        @endforeach
                    </select>
                    <x-ap-icon name="chevron-down" size="sm" />
                </div>
            @elseif (count($units) === 1 || $suffix)
                @php($addon = count($units) === 1 ? reset($units) : $suffix)
                <span class="ap-addon" title="{{ $addon }}"><span class="ap-addon-text">{{ $addon }}</span></span>
            @endif
        </div>
        @if ($unlimited)
            <label class="ap-check"><input type="checkbox" @checked($isUnlimited) :checked="unlimited" x-on:change="toggle()"><span>{{ __('Unlimited') }}</span></label>
        @endif
    </div>
</x-ap-field>
