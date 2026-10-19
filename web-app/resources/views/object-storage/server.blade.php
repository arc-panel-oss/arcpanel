{{-- Server → Object storage (E44 spec §11): install Silo, publish its endpoint, usage per account. --}}
@php
    $size = fn (int $bytes) => \App\Services\Dashboard\DashboardService::size($bytes / 1048576);
    $columns = [
        ['key' => 'user', 'label' => __('Account'), 'sortable' => true],
        ['key' => 'buckets', 'label' => __('Buckets'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'quota', 'label' => __('Quotas'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'used', 'label' => __('Stored'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'objects', 'label' => __('Objects'), 'sortable' => true, 'type' => 'number'],
    ];
@endphp
<x-ap-page :title="__('Object storage service')" :crumbs="[['label' => __('Server')], ['label' => __('Object storage')]]"
    :description="__('One S3-compatible service (Silo) for the buckets of every account.')">

    @unless ($silo->installed)
        <x-ap-card :title="__('Install object storage')" icon="disk">
            <p>{{ __('Installs Silo, the community-maintained fork of MinIO, from checked packages. It listens on this server only; publish it with an endpoint after the installation. Give it a disk of its own when you can: buckets live outside /home.') }}</p>
            @if ($canManage)
                <x-ap-form :action="route('server.object-storage.install')">
                    <x-ap-input name="data_path" :label="__('Data path')" value="/srv/arcpanel-silo" mono required
                        :hint="__('A folder of its own, empty or holding Silo data; not below /home.')" />
                    <div class="ap-form-actions">
                        <x-ap-button type="submit" variant="primary" icon="download">{{ __('Install') }}</x-ap-button>
                    </div>
                </x-ap-form>
            @endif
        </x-ap-card>
    @else
        <x-ap-card :title="__('Service')" icon="server">
            <dl class="ap-dl">
                <dt>{{ __('State') }}</dt>
                <dd><x-ap-badge :tone="$silo->running ? 'ok' : 'danger'">{{ $silo->running ? __('Running') : __('Stopped') }}</x-ap-badge></dd>
                <dt>{{ __('Version') }}</dt><dd class="mono">{{ $silo->version }}</dd>
                <dt>{{ __('Data path') }}</dt><dd class="mono">{{ $silo->dataPath }}</dd>
                <dt>{{ __('Endpoint') }}</dt><dd class="mono">{{ $silo->endpoint !== '' ? $silo->endpoint : __('(none)') }}</dd>
                <dt>{{ __('Stored') }}</dt>
                <dd>{{ $size($silo->bytes) }} · {{ trans_choice(':count bucket|:count buckets', $silo->buckets, ['count' => $silo->buckets]) }} · {{ trans_choice(':count object|:count objects', $silo->objects, ['count' => $silo->objects]) }}</dd>
            </dl>
        </x-ap-card>

        <x-ap-card :title="__('Endpoint')" icon="globe">
            <p>{{ __('Accounts reach their buckets on this hostname. It becomes a web domain of the administrator; its DNS must point at this server for Let\'s Encrypt.') }}</p>
            @if ($canManage)
                <x-ap-form :action="route('server.object-storage.endpoint')">
                    <x-ap-input name="hostname" :label="__('Hostname')" :value="$silo->hostname" mono :hint="__('For example s3.example.com; leave it empty to remove the endpoint.')" />
                    <x-ap-checkbox name="letsencrypt" :label="__('Request a Let\'s Encrypt certificate')" :checked="true" />
                    <div class="ap-form-actions">
                        <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
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
                            <td data-col="buckets" data-label="{{ __('Buckets') }}">{{ $row['buckets'] }}</td>
                            <td data-col="quota" data-label="{{ __('Quotas') }}" data-sort-value="{{ $row['quota'] }}">{{ \App\Services\Dashboard\DashboardService::size((float) $row['quota']) }}</td>
                            <td data-col="used" data-label="{{ __('Stored') }}" data-sort-value="{{ $row['used'] }}">{{ $size($row['used']) }}</td>
                            <td data-col="objects" data-label="{{ __('Objects') }}">{{ $row['objects'] }}</td>
                        </tr>
                    @endforeach
                </x-ap-table>
            @endif
        </x-ap-card>
    @endunless
</x-ap-page>
