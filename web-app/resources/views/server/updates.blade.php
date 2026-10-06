{{-- Updates of the panel packages (web/list/updates, E7 batch 3 spec §10). --}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('Package Name'), 'sortable' => true],
        ['key' => 'description', 'label' => __('Description')],
        ['key' => 'version', 'label' => __('Version'), 'sortable' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ];
    $outdated = array_filter($packages, fn ($p) => ! $p['upToDate']);
@endphp
<x-ap-page :title="__('Updates')" :crumbs="[['label' => __('Server')], ['label' => __('Updates')]]"
    :description="__('Packages of the panel and their automatic updates.')">
    <x-ap-task-panel :task="$task" :title="__('Updating')"
        :done-message="__('The update has finished.')" :fail-message="__('The update failed.')">
        <x-ap-card :title="__('Packages')" icon="package" :flush="true">
            <x-slot:actions>
                @if ($canManage && $packages !== [])
                    <form method="post" action="{{ route('server.updates.update') }}" data-confirm="{{ __('Update every package of the panel now? The panel restarts during the update.') }}">
                        @csrf
                        <x-ap-button type="submit" variant="primary" icon="download" x-bind:disabled="running">{{ __('Update all') }}</x-ap-button>
                    </form>
                @endif
            </x-slot:actions>
            @if ($packages === [])
                <x-ap-empty icon="package" :title="__('No packages')" />
            @else
                <x-ap-table id="server-updates" :columns="$columns" :caption="__('Packages')">
                    @foreach ($packages as $package)
                        <tr data-row>
                            <td data-col="name" data-label="{{ __('Package Name') }}" class="mono" data-sort-value="{{ $package['name'] }}">{{ $package['name'] }}</td>
                            <td data-col="description" data-label="{{ __('Description') }}">{{ $package['description'] }}</td>
                            <td data-col="version" data-label="{{ __('Version') }}" class="mono" data-sort-value="{{ $package['version'] }}">{{ $package['version'] }} <span class="muted">({{ $package['arch'] }})</span></td>
                            <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $package['upToDate'] ? 1 : 0 }}">
                                <x-ap-badge :tone="$package['upToDate'] ? 'ok' : 'warn'">{{ $package['upToDate'] ? __('Package up-to-date') : __('Update available') }}</x-ap-badge>
                            </td>
                            <td class="col-actions">
                                @if ($canManage && ! $package['upToDate'])
                                    <form method="post" action="{{ route('server.updates.update') }}"
                                        data-confirm="{{ sprintf(__('Update %s now? The panel restarts during the update.'), $package['name']) }}">
                                        @csrf
                                        <input type="hidden" name="package" value="{{ $package['name'] }}">
                                        <x-ap-button type="submit" size="sm" icon="download" x-bind:disabled="running">{{ __('Update') }}</x-ap-button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <x-slot:footer>
                        <span class="muted">{{ $outdated === [] ? __('Every package is up to date.') : trans_choice(':count update available|:count updates available', count($outdated), ['count' => count($outdated)]) }}</span>
                    </x-slot:footer>
                </x-ap-table>
            @endif
        </x-ap-card>
    </x-ap-task-panel>

    <x-ap-card :title="__('Automatic updates')" icon="refresh">
        <p>{{ $autoUpdate ? __('The panel installs updates of its packages every day.') : __('The panel does not install updates by itself.') }}</p>
        @if ($canManage)
            <form method="post" action="{{ route('server.updates.autoupdate') }}" class="ap-inline-form">
                @csrf
                <input type="hidden" name="state" value="{{ $autoUpdate ? 'off' : 'on' }}">
                <x-ap-button type="submit" :icon="$autoUpdate ? 'pause' : 'play'">{{ $autoUpdate ? __('Disable Automatic Updates') : __('Enable Automatic Updates') }}</x-ap-button>
            </form>
        @endif
    </x-ap-card>

    <x-ap-card :title="__('Notifications')" icon="mail">
        <x-ap-form :action="route('server.updates.notifications')">
            <fieldset class="ap-fieldset-plain" @disabled(! $canManage)>
                <x-ap-switch name="send_email" :label="__('Send email notification when an update has been installed')" :checked="$sendEmail" />
                <x-ap-switch name="send_log" :label="__('Send update installation log by email')" :checked="$sendLog" />
            </fieldset>
            @if ($canManage)
                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
