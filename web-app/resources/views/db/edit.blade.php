{{-- Edit a database (web/edit/db, E7 batch 1 spec §6.2): user name and password. --}}
@php
    $params = ['user' => $record->user, 'database' => $record->database];
@endphp
<x-ap-page :title="$record->database" :crumbs="[['label' => __('Databases'), 'url' => route('db.index')], ['label' => $record->database]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="db" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $record->user }} · {{ $record->type }} · {{ $record->host }}</span>
                <h1 class="mono">{{ $record->database }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge>{{ $record->charset }}</x-ap-badge>
                </div>
            </div>
        </div>
    </x-slot:head>

    <x-ap-card :title="__('Database')" icon="db">
        <x-ap-form :action="route('db.update', $params)">
            <div class="ap-form-grid">
                <x-ap-input name="database_name" :label="__('Database')" :value="$record->database" mono disabled />
                <x-ap-input name="type_name" :label="__('Type')" :value="$record->type" disabled />
                <x-ap-input name="host_name" :label="__('Host')" :value="$record->host" mono disabled />
                <x-ap-input name="charset_name" :label="__('Charset')" :value="$record->charset" disabled />
                <x-ap-input name="dbuser" :label="__('Username')" :prefix="$record->user.'_'" :value="$record->shortUser()" required mono
                    autocomplete="off" spellcheck="false" :hint="__('Maximum 32 characters length, including prefix')" :disabled="! $can['update']" />
            </div>
            <x-ap-password name="password" :label="__('Password')" :generate="true"
                :hint="__('Leave empty to keep the current password.')" :disabled="! $can['update']" />

            @if ($can['update'])
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button :href="route('db.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
