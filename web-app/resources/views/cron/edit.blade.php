{{-- Edit a cron job (web/edit/cron, E7 batch 1 spec §7.2). --}}
@php
    $params = ['user' => $record->user, 'job' => $record->job];
@endphp
<x-ap-page :title="__('Edit Cron Job')" :crumbs="[['label' => __('Cron Jobs'), 'url' => route('cron.index')], ['label' => __('Edit Cron Job')]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="clock" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $record->user }} · {{ __('Job') }} {{ $record->job }}</span>
                <h1 class="mono ap-trunc" title="{{ $record->command }}">{{ $record->command }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge>{{ $record->expression() }}</x-ap-badge>
                </div>
            </div>
        </div>
    </x-slot:head>

    <x-ap-card :title="__('Cron Job')" icon="clock">
        <x-slot:actions>
            <span class="muted">{{ __('System time') }}: <time class="mono" datetime="{{ $now->format('c') }}">{{ $now->format('d M Y, H:i') }}</time></span>
        </x-slot:actions>
        <x-ap-form :action="route('cron.update', $params)">
            <fieldset class="ap-fieldset-plain" @disabled(! $can['update'])>
                <legend class="sr-only">{{ __('Cron Job') }}</legend>
                <x-ap-input name="cmd" :label="__('Command')" :value="$record->command" required mono autocomplete="off" spellcheck="false" />
                <x-ap-cron-builder :values="$record->schedule()" :required="true" />
            </fieldset>

            @if ($can['update'])
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button :href="route('cron.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
