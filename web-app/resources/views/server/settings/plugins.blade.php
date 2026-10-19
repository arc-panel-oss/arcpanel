{{-- Server settings: Plugins (web/edit/server, E7 batch 3 spec §8.2); installs run as the system task. --}}
@php
    $labels = [
        'filemanager' => [__('File Manager'), __('Browse and edit the files of every account in the panel.')],
        'web-terminal' => [__('Web Terminal'), __('A shell in the browser for accounts with SSH access.')],
        'cgroups' => [__('Resource Limits'), __('Limit CPU and memory of each user with cgroups.')],
        'quota' => [__('Disk Quota'), __('Enforce the disk quota of each user with file system quotas.')],
        'firewall' => [__('Firewall'), __('The nftables firewall of the panel, for IPv4 and IPv6.')],
        'malware-scan' => [__('Malware scan'), __('Find PHP web shells in the web domains of every account, every night and on demand.')],
        'silo' => [__('Object storage'), __('S3-compatible buckets for every account within its package (Silo). Server, Object storage sets the data path and the endpoint.')],
    ];
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Plugins')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('Quick Install App')" icon="package">
        <x-ap-form :action="route('server.settings.update.plugins')">
            <fieldset class="ap-fieldset-plain" @disabled(! $canEdit)>
                <x-ap-switch name="app_installer" :label="__('Quick Install App')" :description="__('Let users install web applications on their domains.')"
                    :checked="($config['PLUGIN_APP_INSTALLER'] ?? 'true') !== 'false'" />
            </fieldset>
            @if ($canEdit)
                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
            @endif
        </x-ap-form>
    </x-ap-card>

    <x-ap-task-panel :task="$task" :title="__('Installing a plugin')"
        :done-message="__('The plugin has been changed.')" :fail-message="__('Changing the plugin failed.')">
        <x-ap-card :title="__('Plugins')" icon="plug" :flush="true">
            <div class="ap-table-wrap">
                <table class="ap-table">
                    <caption class="sr-only">{{ __('Plugins') }}</caption>
                    <thead><tr><th scope="col">{{ __('Plugin') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                    <tbody>
                        @foreach ($plugins as $plugin => $installed)
                            <tr>
                                <td><b>{{ $labels[$plugin][0] }}</b><div class="muted">{{ $labels[$plugin][1] }}</div></td>
                                <td><x-ap-badge :tone="$installed ? 'ok' : 'neutral'">{{ $installed ? __('Installed') : __('Not installed') }}</x-ap-badge></td>
                                <td class="col-actions">
                                    @if ($canEdit)
                                        <form method="post" action="{{ route('server.settings.plugin', ['plugin' => $plugin]) }}"
                                            data-confirm="{{ sprintf($installed ? __('Remove %s?') : __('Install %s?'), $labels[$plugin][0]) }}">
                                            @csrf
                                            <input type="hidden" name="state" value="{{ $installed ? 'off' : 'on' }}">
                                            <x-ap-button type="submit" size="sm" :variant="$installed ? 'danger' : null" :icon="$installed ? 'trash' : 'download'"
                                                x-bind:disabled="running">{{ $installed ? __('Remove') : __('Install') }}</x-ap-button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ap-card>
    </x-ap-task-panel>
</x-ap-page>
