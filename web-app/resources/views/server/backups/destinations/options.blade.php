{{-- Name, description, encryption and bandwidth of a destination (E48 spec §4.1, §4.5). --}}
<x-ap-card :title="__('Options')" icon="sliders">
    <div class="ap-form-grid">
        @unless ($editing)
            <x-ap-input name="name" :label="__('Name')" :value="old('name')" mono required
                :hint="__('Lower-case letters, digits and -, up to 32 characters. Jobs and archives name the destination by it.')" />
        @endunless
        <x-ap-input name="fields[DESCRIPTION]" id="dest-description" :label="__('Description')" :value="$values['DESCRIPTION'] ?? ''" />
        <x-ap-input name="fields[BWLIMIT]" id="dest-bwlimit" :label="__('Bandwidth limit')" :value="$values['BWLIMIT'] ?? ''" mono
            :hint="__('Upload speed limit per second, for example 10M; empty for none.')" />
    </div>
    @php($encryptHint = __('Archives and snapshots are encrypted on this server before they leave it, with a key of this destination. Download its recovery kit after saving.'))
    @if ($editing || old('_token') !== null)
        <x-ap-switch name="fields[ENCRYPT]" id="dest-encrypt" value="yes" :label="__('Encrypt backups')" :checked="$encrypt" :description="$encryptHint" />
    @else
        {{-- On by default for the remote drivers (spec §8.2) --}}
        <x-ap-switch name="fields[ENCRYPT]" id="dest-encrypt" value="yes" :label="__('Encrypt backups')" :checked="$encrypt" :description="$encryptHint"
            x-bind:checked="driver !== 'local'" />
    @endif
    @if ($editing && $encrypt)
        <p class="muted">{{ __('Turning encryption off applies to new archives; existing ones stay encrypted and still need the key.') }}</p>
    @endif
</x-ap-card>
