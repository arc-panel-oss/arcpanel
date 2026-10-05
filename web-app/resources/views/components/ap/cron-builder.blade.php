{{--
    Cron schedule fields (minute, hour, day, month, weekday) with presets
    (apCronBuilder, port of cronGenerator.js). Fields post as {prefix}min …
--}}
@props(['prefix' => '', 'values' => [], 'bag' => null])
@php
    $fields = ['min' => __('Minute'), 'hour' => __('Hour'), 'day' => __('Day'), 'month' => __('Month'), 'wday' => __('Day of week')];
@endphp
<div {{ $attributes->merge(['class' => 'ap-cron-builder']) }} x-data="apCronBuilder({ prefix: @js($prefix) })">
    <div class="ap-form-grid ap-cron-fields">
        @foreach ($fields as $key => $label)
            <x-ap-input :name="$prefix.$key" :label="$label" :value="$values[$key] ?? '*'" :bag="$bag" mono />
        @endforeach
    </div>
    <div class="ap-row" x-cloak>
        <div class="ap-segment" role="group" aria-label="{{ __('Presets') }}">
            <button type="button" x-on:click="preset('minute'); apply()">{{ __('Every minute') }}</button>
            <button type="button" x-on:click="preset('hourly'); apply()">{{ __('Hourly') }}</button>
            <button type="button" x-on:click="preset('daily'); apply()">{{ __('Daily') }}</button>
            <button type="button" x-on:click="preset('weekly'); apply()">{{ __('Weekly') }}</button>
            <button type="button" x-on:click="preset('monthly'); apply()">{{ __('Monthly') }}</button>
        </div>
        <span class="ap-cron-preview"><code x-text="expression">* * * * *</code></span>
    </div>
</div>
