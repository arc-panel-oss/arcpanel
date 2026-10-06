{{-- Server settings: Databases (web/edit/server, E7 batch 3 spec §8.2). --}}
@php
    $systems = explode(',', $config['DB_SYSTEM'] ?? '');
    $mysql = in_array('mysql', $systems, true);
    $pgsql = in_array('pgsql', $systems, true);
    $sso = ($config['PHPMYADMIN_KEY'] ?? '') !== '';
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Databases')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('Databases')" icon="db">
        <x-ap-form :action="route('server.settings.update.databases')" x-data="{ sso: {{ $sso ? 'true' : 'false' }} }">
            <fieldset class="ap-fieldset-plain" @disabled(! $canEdit)>
                <div class="ap-form-grid">
                    @if ($mysql)
                        <x-ap-input name="pma_alias" :label="__('phpMyAdmin Alias')" :value="$config['DB_PMA_ALIAS'] ?? ''" mono />
                    @endif
                    @if ($pgsql)
                        <x-ap-input name="pga_alias" :label="__('phpPgAdmin Alias')" :value="$config['DB_PGA_ALIAS'] ?? ''" mono />
                    @endif
                </div>
                @if ($mysql)
                    <input type="hidden" name="pma_sso_shown" value="1">
                    <x-ap-switch name="pma_sso" :label="__('phpMyAdmin Single Sign On')" :description="__('Users open phpMyAdmin from the Databases page without a password.')" :checked="$sso" x-model="sso" />
                    <input type="hidden" name="pma_restrict_shown" value="1">
                    <div x-show="sso">
                        <x-ap-switch name="pma_restrict" :label="__('Restrict phpMyAdmin access')" :description="__('Only single sign-on logins reach phpMyAdmin.')"
                            :checked="($config['PMA_RESTRICT_ACCESS'] ?? '') === 'yes'" />
                    </div>
                @endif
            </fieldset>
            @if ($canEdit)
                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
            @endif
        </x-ap-form>
    </x-ap-card>

    <x-ap-card :title="__('Database hosts')" icon="hash" :flush="true">
        @if ($hosts === [])
            <x-ap-empty icon="db" :title="__('No database hosts')" />
        @else
            <table class="ap-table">
                <caption class="sr-only">{{ __('Database hosts') }}</caption>
                <thead><tr><th scope="col">{{ __('Host') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Port') }}</th><th scope="col">{{ __('Databases') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                <tbody>
                    @foreach ($hosts as $host)
                        <tr>
                            <td class="mono">{{ $host['host'] }}</td>
                            <td>{{ $host['type'] === 'mysql' ? 'MySQL / MariaDB' : 'PostgreSQL' }}</td>
                            <td class="mono">{{ $host['port'] }}</td>
                            <td>{{ max(0, (int) $host['databases']) }} / {{ $host['maxDb'] }}</td>
                            <td><x-ap-badge :tone="$host['suspended'] ? 'warn' : 'ok'">{{ $host['suspended'] ? __('Suspended') : __('Active') }}</x-ap-badge></td>
                            <td class="col-actions">
                                @if ($canEdit && $host['type'] === 'mysql')
                                    @php($modal = 'dbhost-'.md5($host['host']))
                                    <x-ap-button size="sm" icon="key" x-data x-on:click="$dispatch('ap-modal', { id: '{{ $modal }}' })">{{ __('Change password') }}</x-ap-button>
                                    <x-ap-modal :id="$modal" :title="sprintf(__('Change the password of %s'), $host['host'])" icon="key"
                                        :open="$errors->dbhost->any() && old('host') === $host['host']">
                                        <x-ap-form :action="route('server.settings.dbhost', ['type' => $host['type'], 'host' => $host['host']])" :id="$modal.'-form'">
                                            <input type="hidden" name="host" value="{{ $host['host'] }}">
                                            <x-ap-input name="user" :id="$modal.'-user'" :label="__('Username')" value="root" mono bag="dbhost" required />
                                            <x-ap-password name="password" :id="$modal.'-password'" :label="__('New password')" bag="dbhost" :generate="true" required />
                                        </x-ap-form>
                                        <x-slot:footer>
                                            <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                                            <x-ap-button type="submit" :form="$modal.'-form'" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                                        </x-slot:footer>
                                    </x-ap-modal>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ap-card>
</x-ap-page>
