{{-- A read-only value of the Deploy tab with a copy button (inside apDeploy, or apAccessKeySecret for a one-time secret). --}}
<div class="ap-field">
    <label class="ap-label" for="{{ $id }}">{{ $label }}</label>
    <div class="ap-input-group">
        <input class="ap-input ap-input-mono" id="{{ $id }}" type="text" readonly value="{{ $value }}" x-on:focus="$el.select()" @if ($hint) aria-describedby="{{ $id }}-hint" @endif>
        <button type="button" class="ap-btn" x-on:click="copy(@js($id), $el.previousElementSibling.value)">
            <x-ap-icon name="copy" />
            <span x-text="copied === @js($id) ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
            <span class="sr-only">{{ $label }}</span>
        </button>
    </div>
    @if ($hint)
        <span class="ap-hint" id="{{ $id }}-hint">{{ $hint }}</span>
    @endif
</div>
