{{-- Add a cron job (web/add/cron, E7 batch 1 spec §7.2). --}}
<x-ap-page :title="__('Add Cron Job')" :crumbs="[['label' => __('Cron Jobs'), 'url' => route('cron.index')], ['label' => __('Add Cron Job')]]"
    :description="$owners !== null ? __('The cron job runs as the owner you choose.') : null">

    <x-ap-owner-picker route="cron.create" :owners="$owners" :owner="$owner" :admin="$ownerIsAdmin" :object="__('a cron job')" />

    <x-ap-card :title="__('Cron Job')" icon="clock">
        <x-slot:actions>
            <span class="muted">{{ __('System time') }}: <time class="mono" datetime="{{ $now->format('c') }}">{{ $now->format('d M Y, H:i') }}</time></span>
        </x-slot:actions>
        <x-ap-form :action="route('cron.store')" :focus-first="true">
            <input type="hidden" name="owner" value="{{ $owner }}">
            <x-ap-input name="cmd" :label="__('Command')" required mono autocomplete="off" spellcheck="false" />
            <x-ap-cron-builder :values="['min' => '', 'hour' => '', 'day' => '', 'month' => '', 'wday' => '']" :required="true" />

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('cron.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
