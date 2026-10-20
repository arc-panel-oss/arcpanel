{{-- Server → Object storage (E44 spec §11): install Silo, publish its endpoint, usage per account.
     Install and remove run as the system task, shown above the cards; their buttons wait for it. --}}
@php
    $size = fn (int $bytes) => \App\Services\Dashboard\DashboardService::size($bytes / 1048576);
    $columns = [
        ['key' => 'user', 'label' => __('Account'), 'sortable' => true],
        ['key' => 'buckets', 'label' => __('Buckets'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'quota', 'label' => __('Quota total'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'used', 'label' => __('Stored'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'objects', 'label' => __('Objects'), 'sortable' => true, 'type' => 'number'],
    ];
    [$storedValue, $storedUnit] = explode(' ', $size($silo->bytes), 2);
    $tiles = [
        ['label' => __('Stored'), 'icon' => 'disk', 'value' => $storedValue, 'unit' => $storedUnit],
        ['label' => __('Buckets'), 'icon' => 'archive', 'value' => (string) $silo->buckets, 'unit' => ''],
        ['label' => __('Objects'), 'icon' => 'file', 'value' => (string) $silo->objects, 'unit' => ''],
        ['label' => __('Accounts'), 'icon' => 'users', 'value' => (string) $silo->accounts, 'unit' => ''],
    ];
@endphp
<x-ap-page :title="__('Object storage')" :crumbs="[['label' => __('Server')], ['label' => __('Object storage')]]"
    :description="__('One S3-compatible service (Silo) for the buckets of every account.')">
    <x-ap-task-panel :task="$task" :title="__('System task')"
        :done-message="__('The system task has finished.')" :fail-message="__('The system task failed.')">

    @unless ($silo->installed)
        <x-ap-card :title="__('Install object storage')" icon="disk">
            <x-slot:actions><x-ap-badge>{{ __('Not installed') }}</x-ap-badge></x-slot:actions>
            <div class="ap-split ap-storage-install">
                <div class="ap-stack">
                    <h3>{{ __('Object storage is not installed') }}</h3>
                    <ul class="ap-check-list">
                        <li><x-ap-icon name="package" /><span>{{ __('Silo, the community-maintained fork of MinIO, from checked packages.') }}</span></li>
                        <li><x-ap-icon name="lock" /><span>{{ __('It listens on this server only; publish an endpoint after the installation.') }}</span></li>
                        <li><x-ap-icon name="disk" /><span>{{ __('Buckets live outside /home: give it a disk of its own when you can.') }}</span></li>
                    </ul>
                </div>
                @if ($canManage)
                    <x-ap-form :action="route('server.object-storage.install')">
                        <x-ap-input name="data_path" :label="__('Data path')" value="/srv/arcpanel-silo" mono required
                            :hint="__('A folder of its own, empty or holding Silo data; not below /home.')" />
                        <div class="ap-form-actions">
                            <x-ap-button type="submit" variant="primary" icon="download" x-bind:disabled="running">{{ __('Install object storage') }}</x-ap-button>
                        </div>
                        <p class="muted" role="status" x-show="running && task.args === 'silo on'" x-cloak>{{ __('Silo is being installed: this page shows the service when the task has finished.') }}</p>
                        <p class="muted" role="status" x-show="running && task.args !== 'silo on'" x-cloak>{{ __('Another system task is running: the installation can start when it has finished.') }}</p>
                    </x-ap-form>
                @endif
            </div>
        </x-ap-card>
    @else
        <x-ap-card :title="__('Service')" icon="server">
            <x-slot:actions><x-ap-badge :tone="$silo->running ? 'ok' : 'danger'">{{ $silo->running ? __('Running') : __('Stopped') }}</x-ap-badge></x-slot:actions>
            @unless ($silo->running)
                <div class="ap-alert is-danger" role="status">
                    <x-ap-icon name="alert" />
                    <div class="ap-alert-body">
                        <p>{{ __('Silo is stopped: no bucket answers until the service starts again.') }}</p>
                        <p><a href="{{ route('server.services') }}">{{ __('Open the Services page') }}</a></p>
                    </div>
                </div>
            @endunless
            <dl class="ap-fact-strip">
                <div><dt>{{ __('Version') }}</dt><dd title="{{ $silo->version }}">{{ sprintf(__('Silo release %s'), $silo->release()) }}</dd></div>
                <div><dt>{{ __('Data path') }}</dt><dd>{{ $silo->dataPath }}</dd></div>
                <div><dt>{{ __('Region') }}</dt><dd>{{ $silo->region !== '' ? $silo->region : '—' }}</dd></div>
            </dl>
            <ul class="ap-stats-tiles ap-stats-tiles-compact" aria-label="{{ __('Usage') }}">
                @foreach ($tiles as $tile)
                    <li class="ap-stats-tile">
                        <span class="ap-stats-tile-icon" aria-hidden="true"><x-ap-icon :name="$tile['icon']" /></span>
                        <span class="ap-stats-tile-label">{{ $tile['label'] }}</span>
                        <span class="ap-stats-tile-value">{{ $tile['value'] }}@if ($tile['unit'] !== '')<small>{{ $tile['unit'] }}</small>@endif</span>
                    </li>
                @endforeach
            </ul>
            @if ($canManage)
                <x-slot:footer>
                    <span class="muted">{{ $silo->buckets > 0 ? __('Removing needs every bucket deleted first.') : __('Removing stops the service and keeps the data folder.') }}</span>
                    <x-ap-button variant="danger" size="sm" icon="trash" :form-action="route('server.object-storage.remove')" form-method="DELETE" :disabled="$silo->buckets > 0"
                        x-bind:disabled="{{ $silo->buckets > 0 ? 'true' : 'running' }}"
                        :confirm="__('Remove object storage from this server? The service stops and its packages are removed; the data folder stays.')" :confirm-danger="true">{{ __('Remove') }}</x-ap-button>
                </x-slot:footer>
            @endif
        </x-ap-card>

        <x-ap-card :title="__('Endpoint')" icon="globe">
            <x-slot:actions><x-ap-badge :tone="$silo->endpoint !== '' ? 'ok' : null">{{ $silo->endpoint !== '' ? __('Published') : __('Not published') }}</x-ap-badge></x-slot:actions>
            @if ($silo->endpoint !== '')
                <p>{{ __('Accounts reach their buckets at') }} <a class="mono" href="{{ $silo->endpoint }}" target="_blank" rel="noopener noreferrer">{{ $silo->endpoint }}</a></p>
            @else
                <div class="ap-alert is-info" role="note">
                    <x-ap-icon name="info" />
                    <div class="ap-alert-body"><p>{{ sprintf(__('Not published; apps on this server use %s'), \App\Services\ObjectStorage\AccountStorage::LOCAL_ENDPOINT) }}</p></div>
                </div>
            @endif
            <p class="muted">{{ __('Accounts reach their buckets on this hostname. It becomes a web domain of the administrator; its DNS must point at this server for Let\'s Encrypt.') }}</p>
            @if ($canManage)
                <x-ap-form :action="route('server.object-storage.endpoint')">
                    <x-ap-input name="hostname" :label="__('Hostname')" :value="$silo->hostname" mono placeholder="s3.example.com" :hint="__('For example s3.example.com; leave it empty to remove the endpoint.')" />
                    <x-ap-checkbox name="letsencrypt" :label="__('Request a Let\'s Encrypt certificate')" :checked="true" />
                    <div class="ap-form-actions">
                        <x-ap-button type="submit" variant="primary" icon="check">{{ $silo->endpoint !== '' ? __('Save') : __('Publish') }}</x-ap-button>
                    </div>
                </x-ap-form>
            @endif
        </x-ap-card>

        <x-ap-card :title="__('Usage per account')" icon="chart" :flush="true">
            @if ($accounts === [])
                <x-ap-empty icon="archive" :title="__('No account has a bucket yet.')" />
            @else
                <x-ap-table id="storage-accounts" :columns="$columns" :search-label="__('Search accounts')" :caption="__('Usage per account')" :actions-column="false">
                    @foreach ($accounts as $row)
                        <tr data-row data-search="{{ $row['user'] }}">
                            <td data-col="user" data-label="{{ __('Account') }}"><a href="{{ route('storage.show', ['user' => $row['user']]) }}">{{ $row['user'] }}</a></td>
                            <td data-col="buckets" data-label="{{ __('Buckets') }}" class="col-num mono">{{ $row['buckets'] }}</td>
                            <td data-col="quota" data-label="{{ __('Quota total') }}" class="col-num mono" data-sort-value="{{ $row['quota'] }}">{{ \App\Services\Dashboard\DashboardService::size((float) $row['quota']) }}</td>
                            <td data-col="used" data-label="{{ __('Stored') }}" class="col-num mono" data-sort-value="{{ $row['used'] }}">{{ $size($row['used']) }}</td>
                            <td data-col="objects" data-label="{{ __('Objects') }}" class="col-num mono">{{ $row['objects'] }}</td>
                        </tr>
                    @endforeach
                </x-ap-table>
            @endif
        </x-ap-card>
    @endunless
    </x-ap-task-panel>
</x-ap-page>
