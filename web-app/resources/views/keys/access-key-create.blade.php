{{-- Add an API access key (web/add/access-key, E7 batch 2 spec §8.2; comment first and grouped set cards: spec 2026-10-08-008 U3). --}}
@php
    $chosen = (array) old('sets', []);
    $error = $errors->first('sets');
@endphp
<x-ap-page :title="__('Add Access Key')" :crumbs="[['label' => __('Account')], ['label' => __('Access Keys'), 'url' => route('access-keys.list', ['user' => $user])], ['label' => __('Add Access Key')]]"
    :description="__('The secret of the new key is shown once, right after you save it.')">
    <x-ap-card :title="__('Access Key')" icon="key">
        <x-ap-form :action="route('access-keys.store', ['user' => $user])" :focus-first="true">
            <x-ap-input name="comment" :label="__('Comment')" :hint="__('Optional. What the key is for, for example deploy bot. Up to 66 letters, digits, spaces, dots, dashes or underscores.')" maxlength="66" autocomplete="off" />

            <fieldset class="ap-fieldset" @if ($error) aria-describedby="sets-error" @endif>
                <legend class="ap-label">{{ __('Permissions') }} <span class="ap-required" aria-hidden="true">*</span></legend>
                @if ($error)
                    <span class="ap-error ap-sets-error" id="sets-error"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>
                @endif
                @forelse ($groups as $index => $group)
                    <div class="ap-set-group" role="group" aria-labelledby="set-group-{{ $index }}">
                        <h3 class="ap-key-heading" id="set-group-{{ $index }}">{{ $group['label'] }}</h3>
                        @if ($group['note'])
                            <p class="ap-hint">{{ $group['note'] }}</p>
                        @endif
                        <div class="ap-sets">
                            @foreach ($group['sets'] as $set)
                                @php
                                    $summary = \App\Services\Keys\AccessKeySets::summary($set);
                                    $describedBy = \App\View\FormField::describedBy([$summary ? 'set-'.$set.'-summary' : null, $error ? 'sets-error' : null]);
                                @endphp
                                <div class="ap-set">
                                    <label class="ap-check" for="set-{{ $set }}">
                                        <input type="checkbox" id="set-{{ $set }}" name="sets[]" value="{{ $set }}" @checked(in_array($set, $chosen, true))
                                            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif>
                                        <span class="mono">{{ $set }}</span>
                                    </label>
                                    @if ($summary)
                                        <p class="ap-hint" id="set-{{ $set }}-summary">{{ $summary }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="muted">{{ __('No permission sets are available for this account.') }}</p>
                @endforelse
            </fieldset>

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('access-keys.list', ['user' => $user])" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
