{{--
    Multi-step form (apWizard). steps: list of step titles; the slot holds one
    <fieldset data-step> per step. Without JavaScript all steps show at once.
--}}
@props(['steps' => [], 'submitLabel' => null])
<div {{ $attributes->merge(['class' => 'ap-wizard']) }} x-data="apWizard()">
    <ol class="ap-steps">
        @foreach ($steps as $index => $title)
            <li x-bind:class="{ 'is-done': step > {{ $index }}, 'is-current': step === {{ $index }} }" x-bind:aria-current="step === {{ $index }} ? 'step' : null">
                <span class="ap-step-num"><span x-show="step <= {{ $index }}">{{ $index + 1 }}</span><x-ap-icon name="check" size="sm" x-show="step > {{ $index }}" x-cloak /></span>{{ $title }}
            </li>
        @endforeach
    </ol>
    {{ $slot }}
    <div class="ap-between">
        <span class="muted ap-wizard-count" x-cloak x-text="@js(__('Step')) + ' ' + (step + 1) + ' / ' + count"></span>
        <div class="ap-actions">
            <button type="button" class="ap-btn ap-btn-ghost" x-on:click="back()" x-show="!isFirst" x-cloak>{{ __('Back') }}</button>
            <button type="button" class="ap-btn ap-btn-primary" x-on:click="next()" x-show="!isLast" x-cloak><x-ap-icon name="arrow" />{{ __('Continue') }}</button>
            <button type="submit" class="ap-btn ap-btn-primary" x-show="isLast"><x-ap-icon name="check" />{{ $submitLabel ?? __('Save') }}</button>
        </div>
    </div>
</div>
