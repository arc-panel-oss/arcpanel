{{-- Name servers 1–8 of a new DNS zone (apNameServers): two fields at least, "Add Name Server" up to eight. --}}
@php
    $values = array_values(array_map('strval', array_filter((array) $values, 'is_scalar')));
@endphp
<fieldset class="ap-fieldset ap-ns" x-data="apNameServers({ values: @js($values) })">
    <legend class="ap-label">{{ __('Name Servers') }}</legend>
    <div class="ap-ns-list">
        <template x-for="(server, index) in servers" :key="index">
            <div class="ap-ns-row">
                <input class="ap-input ap-input-mono" type="text" name="ns[]" x-model="servers[index]" :data-ns-index="index"
                    :id="'f-ns-' + index" :aria-label="@js(__('Name server')) + ' ' + (index + 1)" autocomplete="off" spellcheck="false">
                <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon" x-show="canRemove" x-on:click="remove(index)"
                    :aria-label="@js(__('Remove name server')) + ' ' + (index + 1)" title="{{ __('Remove name server') }}"><x-ap-icon name="x" /></button>
            </div>
        </template>
    </div>
    @error('ns.*')
        <span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $message }}</span>
    @enderror
    <div>
        <x-ap-button size="sm" variant="ghost" icon="plus" x-show="canAdd" x-on:click="add()">{{ __('Add Name Server') }}</x-ap-button>
    </div>
</fieldset>
