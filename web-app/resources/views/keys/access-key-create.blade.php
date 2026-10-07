{{-- Add an API access key (web/add/access-key, E7 batch 2 spec §8.2). --}}
@php
    $chosen = (array) old('sets', []);
    $error = $errors->first('sets');
@endphp
<x-ap-page :title="__('Add Access Key')" :crumbs="[['label' => __('Account')], ['label' => __('Access Keys'), 'url' => route('access-keys.list', ['user' => $user])], ['label' => __('Add Access Key')]]">
    <x-ap-card :title="__('Access Key')" icon="key">
        <x-ap-form :action="route('access-keys.store', ['user' => $user])">
            <fieldset class="ap-fieldset" @if ($error) aria-describedby="sets-error" @endif>
                <legend>{{ __('Permissions') }} <span class="ap-required" aria-hidden="true">*</span></legend>
                @forelse ($sets as $set)
                    <div class="ap-field ap-field-check">
                        <label class="ap-check" for="set-{{ $set }}">
                            <input type="checkbox" id="set-{{ $set }}" name="sets[]" value="{{ $set }}" @checked(in_array($set, $chosen, true))>
                            <span>{{ __($set) }}</span>
                        </label>
                        @if (isset($setHints[$set]))
                            <p class="ap-hint">{{ $setHints[$set] }}</p>
                        @endif
                    </div>
                @empty
                    <p class="muted">{{ __('No permission sets are available for this account.') }}</p>
                @endforelse
                @if ($error)
                    <span class="ap-error" id="sets-error"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>
                @endif
            </fieldset>
            <x-ap-input name="comment" :label="__('Comment')" :hint="__('(Optional)')" maxlength="255" autocomplete="off" />

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('access-keys.list', ['user' => $user])" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
