{{--
    Cron schedule fields (minute, hour, day, month, weekday) with the generator
    tabs of the legacy add and edit pages (apCronBuilder, port of
    cronGenerator.js). Fields post as {prefix}min …; the generator selects have
    no name and are never posted.
--}}
@props(['prefix' => '', 'values' => [], 'bag' => null, 'required' => false])
@php
    $fields = ['min' => __('Minute'), 'hour' => __('Hour'), 'day' => __('Day'), 'month' => __('Month'), 'wday' => __('Day of Week')];
    $id = 'cron-gen-'.($prefix !== '' ? trim($prefix, '_') : 'job');
    $pad = fn (array $numbers) => array_combine(array_map('strval', $numbers), array_map(fn ($n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT), $numbers));
    $hours = $pad(range(0, 23));
    $minutes = $pad([0, 1, 2, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]);
    $tabs = [
        'minutes' => [
            'label' => __('Minutes'),
            'selects' => [
                'min' => [__('Run Command'), ['*' => __('Every minute')] + collect([2, 5, 10, 15, 30])->mapWithKeys(fn ($n) => ["*/{$n}" => sprintf(__('Every %s minutes'), $n)])->all()],
            ],
        ],
        'hourly' => [
            'label' => __('Hourly'),
            'selects' => [
                'hour' => [__('Run Command'), ['*' => __('Every hour')] + collect([2, 6, 12])->mapWithKeys(fn ($n) => ["*/{$n}" => sprintf(__('Every %s hours'), $n)])->all()],
                'min' => [__('Minute'), $pad([0, 15, 30, 45])],
            ],
        ],
        'daily' => [
            'label' => __('Daily'),
            'selects' => [
                // The legacy page labelled */3 "Every 2 days" (spec §7.2).
                'day' => [__('Run Command'), ['*' => __('Every day'), '1-31/2' => __('Every odd day'), '*/2' => __('Every even day')]
                    + collect([3, 5, 10, 15])->mapWithKeys(fn ($n) => ["*/{$n}" => sprintf(__('Every %s days'), $n)])->all()],
                'hour' => [__('Hour'), $hours],
                'min' => [__('Minute'), $minutes],
            ],
        ],
        'weekly' => [
            'label' => __('Weekly'),
            'selects' => [
                'wday' => [__('Run Command'), [
                    '*' => __('Every day'), '1,2,3,4,5' => __('Weekdays (5 days)'), '0,6' => __('Weekend (2 days)'),
                    '1' => __('Monday'), '2' => __('Tuesday'), '3' => __('Wednesday'), '4' => __('Thursday'),
                    '5' => __('Friday'), '6' => __('Saturday'), '0' => __('Sunday'),
                ]],
                'hour' => [__('Hour'), $hours],
                'min' => [__('Minute'), $minutes],
            ],
        ],
        'monthly' => [
            'label' => __('Monthly'),
            'selects' => [
                'month' => [__('Run Command'), ['*' => __('Every month'), '1-11/2' => __('Every odd month'), '*/2' => __('Every even month')]
                    + collect([3, 6])->mapWithKeys(fn ($n) => ["*/{$n}" => sprintf(__('Every %s months'), $n)])->all()
                    + array_combine(array_map('strval', range(1, 12)), [__('Jan'), __('Feb'), __('Mar'), __('Apr'), __('May'), __('Jun'), __('Jul'), __('Aug'), __('Sep'), __('Oct'), __('Nov'), __('Dec')])],
                'day' => [__('Day'), $pad(range(1, 31))],
                'hour' => [__('Hour'), $hours],
                'min' => [__('Minute'), $minutes],
            ],
        ],
    ];
@endphp
<div {{ $attributes->merge(['class' => 'ap-cron-builder']) }} x-data="apCronBuilder({ prefix: @js($prefix) })">
    <div class="ap-form-grid ap-cron-fields">
        @foreach ($fields as $key => $label)
            <x-ap-input :name="$prefix.$key" :label="$label" :value="$values[$key] ?? '*'" :bag="$bag" :required="$required" mono autocomplete="off" spellcheck="false" />
        @endforeach
    </div>
    <div class="ap-cron-generator" x-cloak>
        <div class="ap-segment" role="group" aria-label="{{ __('Schedule generator') }}">
            @foreach ($tabs as $tab => $definition)
                <button type="button" x-on:click="tab = @js($tab)" x-bind:aria-pressed="(tab === @js($tab)).toString()"
                    aria-controls="{{ $id }}-{{ $tab }}">{{ $definition['label'] }}</button>
            @endforeach
        </div>
        @foreach ($tabs as $tab => $definition)
            <div class="ap-cron-tab ap-row" id="{{ $id }}-{{ $tab }}" x-show="tab === @js($tab)" @if (! $loop->first) style="display: none" @endif>
                @foreach ($definition['selects'] as $field => [$selectLabel, $options])
                    <div class="ap-field">
                        <label class="ap-label" for="{{ $id }}-{{ $tab }}-{{ $field }}">{{ $selectLabel }}</label>
                        <div class="ap-select-wrap">
                            <select class="ap-select" id="{{ $id }}-{{ $tab }}-{{ $field }}" x-model="gen.{{ $tab }}.{{ $field }}">
                                @foreach ($options as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                            <x-ap-icon name="chevron-down" size="sm" />
                        </div>
                    </div>
                @endforeach
                <x-ap-button type="button" x-on:click="generate()" icon="refresh">{{ __('Generate') }}</x-ap-button>
            </div>
        @endforeach
        <span class="ap-cron-preview"><span class="muted">{{ __('Schedule') }}</span><code x-text="expression">{{ implode(' ', array_map(fn ($key) => ($values[$key] ?? '*') !== '' ? ($values[$key] ?? '*') : '?', array_keys($fields))) }}</code></span>
    </div>
</div>
