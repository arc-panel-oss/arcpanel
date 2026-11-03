{{--
    The actions of a long form, kept in view at the bottom of the window. With
    apFormState on the form it says when there are unsaved changes.
--}}
<div {{ $attributes->merge(['class' => 'ap-form-bar']) }} data-form-bar :class="{ 'is-dirty': dirty }">
    <p class="ap-form-bar-state" aria-live="polite">
        <span class="ap-form-bar-dirty" x-show="dirty" x-cloak><span class="ap-dot" aria-hidden="true"></span>{{ __('Unsaved changes') }}</span>
    </p>
    <div class="ap-form-bar-actions">{{ $slot }}</div>
</div>
