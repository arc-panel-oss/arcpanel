{{-- Server → PHP (E41 spec 2026-10-12-001 §8): the versions of the catalog. --}}
@php
    $statusTone = ['active' => 'ok', 'security' => 'warn', 'eol' => 'danger'];
    $statusLabel = ['active' => __('Active support'), 'security' => __('Security fixes only'), 'eol' => __('End of life'), 'unknown' => __('Unknown')];
    $installed = array_filter($versions, fn ($v) => $v['installed']);
@endphp
<x-ap-page :title="__('PHP')" :crumbs="[['label' => __('Server')], ['label' => __('PHP')]]"
    :description="__('PHP versions, their extensions and php.ini, and the PHP-FPM profiles that packages offer to web domains.')">
    <x-slot:actions>
        <x-ap-button :href="route('server.php.profiles.index')" icon="sliders">{{ __('PHP-FPM profiles') }}</x-ap-button>
    </x-slot:actions>

    @unless ($fpm)
        <x-ap-alert tone="info" :title="__('No PHP-FPM backend')">{{ __('This server runs PHP without PHP-FPM; the versions are shown read-only.') }}</x-ap-alert>
    @endunless

    @if ($fpm && $installed !== [])
        <x-ap-card :title="__('Default PHP Version')" icon="hexagon">
            <x-ap-form :action="route('server.php.default')">
                <x-ap-select name="version" :label="__('Default PHP Version')" :options="array_combine(array_keys($installed), array_keys($installed))" :value="$default"
                    :hint="__('Version of the php command and of web domains on the default backend template.')" :disabled="! $canManage" />
                @if ($canManage)
                    <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                @endif
            </x-ap-form>
        </x-ap-card>
    @endif

    <x-ap-task-panel :task="$task" :title="__('Changing PHP')"
        :done-message="__('The task has finished.')" :fail-message="__('The task failed.')">
        <x-ap-card :title="__('PHP versions')" icon="layers" flush>
            <x-ap-table id="php-versions" :searchable="false" :caption="__('PHP versions')" :columns="[
                ['key' => 'version', 'label' => __('Version')],
                ['key' => 'status', 'label' => __('Support')],
                ['key' => 'eol', 'label' => __('End of life')],
                ['key' => 'domains', 'label' => __('Web domains'), 'type' => 'number'],
                ['key' => 'extensions', 'label' => __('Extensions'), 'type' => 'number'],
            ]">
                @foreach ($versions as $version => $state)
                    <tr data-row data-php-version="{{ $version }}">
                        <td data-col="version" data-label="{{ __('Version') }}">
                            @if ($state['installed'])
                                <a class="mono" href="{{ route('server.php.show', ['version' => $version]) }}"><b>PHP {{ $version }}</b></a>
                            @else
                                <span class="mono muted">PHP {{ $version }}</span>
                            @endif
                            <span class="ap-row">
                                @if ($state['default'])
                                    <x-ap-badge tone="info">{{ __('Default') }}</x-ap-badge>
                                @endif
                                @if ($state['installed'])
                                    <x-ap-badge tone="ok">{{ __('Installed') }}</x-ap-badge>
                                @elseif (! $state['installable'])
                                    <x-ap-badge>{{ __('No longer installable') }}</x-ap-badge>
                                @elseif (! $state['available'])
                                    <x-ap-badge>{{ __('Not available from the PHP repository') }}</x-ap-badge>
                                @endif
                            </span>
                        </td>
                        <td data-col="status" data-label="{{ __('Support') }}">
                            <x-ap-badge :tone="$statusTone[$state['status']] ?? null">{{ $statusLabel[$state['status']] ?? $state['status'] }}</x-ap-badge>
                        </td>
                        <td data-col="eol" data-label="{{ __('End of life') }}" class="mono">{{ $state['eol'] ?: '—' }}</td>
                        <td data-col="domains" data-label="{{ __('Web domains') }}" class="col-num mono" title="{{ implode(', ', array_slice($state['usedBy'], 0, 20)) }}">{{ $state['installed'] ? count($state['usedBy']) : '—' }}</td>
                        <td data-col="extensions" data-label="{{ __('Extensions') }}" class="col-num mono">{{ $state['installed'] ? $state['extensions'] : '—' }}</td>
                        <td class="col-actions">
                            @if ($canManage && $fpm)
                                @if (! $state['installed'] && $state['installable'] && $state['available'])
                                    <form method="post" action="{{ route('server.php.versions') }}" data-confirm="{{ sprintf(__('Install PHP %s from the PHP repository now?'), $version) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="install">
                                        <input type="hidden" name="version" value="{{ $version }}">
                                        <x-ap-button type="submit" size="sm" variant="primary" icon="download" x-bind:disabled="running">{{ __('Install') }}</x-ap-button>
                                    </form>
                                @elseif ($state['installed'] && ($state['default'] || $state['usedBy'] !== []))
                                    <x-ap-button size="sm" icon="trash" disabled :title="$state['default'] ? __('Default version') : sprintf(__('Used by %s'), implode(', ', array_slice($state['usedBy'], 0, 5)))">{{ __('Remove') }}</x-ap-button>
                                @elseif ($state['installed'])
                                    <form method="post" action="{{ route('server.php.versions') }}" data-confirm="{{ sprintf(__('Remove PHP %s with its extensions?'), $version) }}" data-confirm-danger>
                                        @csrf
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="version" value="{{ $version }}">
                                        <x-ap-button type="submit" size="sm" icon="trash" x-bind:disabled="running">{{ __('Remove') }}</x-ap-button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        </x-ap-card>
    </x-ap-task-panel>
</x-ap-page>
