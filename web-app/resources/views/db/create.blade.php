{{-- Add a database (web/add/db, E7 batch 1 spec §6.2). --}}
<x-ap-page :title="__('Add Database')" :crumbs="[['label' => __('Databases'), 'url' => route('db.index')], ['label' => __('Add Database')]]"
    :description="$owners !== null ? __('The database belongs to the owner you choose.') : null">

    <x-ap-owner-picker route="db.create" :owners="$owners" :owner="$owner" :admin="$ownerIsAdmin" :object="__('a database')" />

    <x-ap-card :title="__('Database')" icon="db">
        <x-ap-form :action="route('db.store')" :focus-first="true">
            <input type="hidden" name="owner" value="{{ $owner }}">
            <x-ap-alert>{{ sprintf(__('Prefix %s will be automatically added to database name and database user'), $owner.'_') }}</x-ap-alert>
            <div class="ap-form-grid">
                <x-ap-input name="database" :label="__('Database')" :prefix="$owner.'_'" required mono autocomplete="off" spellcheck="false" />
                <x-ap-input name="dbuser" :label="__('Username')" :prefix="$owner.'_'" required mono autocomplete="off" spellcheck="false"
                    :hint="__('Maximum 32 characters length, including prefix')" />
            </div>
            <x-ap-password name="password" :label="__('Password')" required :generate="true"
                :hint="__('At least 8 characters with upper and lower case letters and a digit.')" />
            <x-ap-input name="email" type="email" :label="__('Send login credentials to email address')" autocomplete="email" />

            <details class="ap-advanced" @if ($errors->hasAny(['type', 'host', 'charset'])) open @endif>
                <summary>{{ __('Advanced Options') }}</summary>
                <div class="ap-form-grid">
                    <x-ap-select name="type" :label="__('Type')" :options="$types" :value="array_key_first($types)" required />
                    <x-ap-select name="host" :label="__('Host')" :options="array_combine($hosts, $hosts)" :value="$hosts[0] ?? 'localhost'" required />
                    <x-ap-select name="charset" :label="__('Charset')" :options="$charsets" value="utf8mb4" required />
                </div>
            </details>

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('db.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
