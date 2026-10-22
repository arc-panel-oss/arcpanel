{{-- Backups hub, Destinations (E48 spec §8.2): where the jobs send archives and snapshots. --}}
@use('App\Services\Backups\BackupDestination')
@use('App\Services\Backups\BackupJobRun')
@php
    $stateLabel = ['ok' => __('Working'), 'failing' => __('Failing'), 'unchecked' => __('Not checked')];
@endphp
<x-ap-page :title="__('Backup destinations')" :crumbs="[['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')], ['label' => __('Destinations')]]"
    :description="__('A destination is a folder, a server or a cloud storage that receives backups. Its credentials are encrypted on this server and never shown again.')">
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button :href="route('server.backups.destinations.create')" variant="primary" icon="plus">{{ __('Add destination') }}</x-ap-button>
        </x-slot:actions>
    @endif

    @include('server.backups.partials.check-result')

    <x-ap-card :title="__('Destinations')" icon="disk" flush>
        @if ($destinations === [])
            <x-ap-empty icon="disk" :title="__('No destinations yet')">
                {{ __('Add a local folder, an SFTP server, an S3 bucket or a cloud drive.') }}
                @if ($canManage)
                    <x-slot:actions><x-ap-button :href="route('server.backups.destinations.create')" variant="primary" icon="plus">{{ __('Add destination') }}</x-ap-button></x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            <x-ap-table id="backup-destinations" :caption="__('Backup destinations')" :columns="[
                ['key' => 'name', 'label' => __('Name')],
                ['key' => 'driver', 'label' => __('Type')],
                ['key' => 'location', 'label' => __('Location'), 'optional' => true],
                ['key' => 'encrypt', 'label' => __('Encryption')],
                ['key' => 'state', 'label' => __('State')],
                ['key' => 'jobs', 'label' => __('Jobs')],
            ]">
                @foreach ($destinations as $destination)
                    <tr data-row data-destination="{{ $destination->name }}" data-search="{{ $destination->name }} {{ $destination->driver }} {{ $destination->description }}">
                        <td data-col="name" data-label="{{ __('Name') }}">
                            <a class="mono" href="{{ route('server.backups.destinations.edit', ['destination' => $destination->name]) }}"><b>{{ $destination->name }}</b></a>
                            @if ($destination->description !== '')
                                <small class="muted ap-cell-note">{{ $destination->description }}</small>
                            @endif
                        </td>
                        <td data-col="driver" data-label="{{ __('Type') }}"><span class="ap-row"><x-ap-icon :name="BackupDestination::driverIcon($destination->driver)" size="sm" />{{ BackupDestination::driverLabel($destination->driver) }}</span></td>
                        <td data-col="location" data-label="{{ __('Location') }}" class="mono">{{ $destination->location() }}</td>
                        <td data-col="encrypt" data-label="{{ __('Encryption') }}">
                            @if ($destination->encrypt)
                                <x-ap-badge tone="ok">{{ __('Encrypted') }}</x-ap-badge>
                                @if ($destination->needsKit())
                                    <x-ap-badge tone="warn">{{ __('No recovery kit') }}</x-ap-badge>
                                @endif
                            @else
                                <x-ap-badge>{{ __('Not encrypted') }}</x-ap-badge>
                            @endif
                        </td>
                        <td data-col="state" data-label="{{ __('State') }}">
                            <x-ap-badge :tone="BackupJobRun::statusTone($destination->state)">{{ $stateLabel[$destination->state] ?? $destination->state }}</x-ap-badge>
                            @if ($destination->checked !== '')
                                <small class="muted ap-cell-note">{{ sprintf(__('Checked %s'), $destination->checked) }}</small>
                            @endif
                        </td>
                        <td data-col="jobs" data-label="{{ __('Jobs') }}">
                            @forelse ($destination->jobs as $id)
                                <a href="{{ route('server.backups.jobs.edit', ['job' => $id]) }}">{{ ($jobs[$id] ?? null)?->name ?? $id }}</a>@if (! $loop->last), @endif
                            @empty
                                <span class="muted">—</span>
                            @endforelse
                        </td>
                        <td class="col-actions">
                            @if ($canManage)
                                <div class="ap-row">
                                    <x-ap-button size="sm" icon="refresh" :form-action="route('server.backups.destinations.check', ['destination' => $destination->name])"
                                        :aria-label="sprintf(__('Test the connection of %s'), $destination->name)">{{ __('Test') }}</x-ap-button>
                                    <x-ap-button size="sm" icon="edit" :href="route('server.backups.destinations.edit', ['destination' => $destination->name])"
                                        :aria-label="sprintf(__('Edit destination %s'), $destination->name)">{{ __('Edit') }}</x-ap-button>
                                    @if ($destination->jobs === [])
                                        <x-ap-button size="sm" icon="trash" :form-action="route('server.backups.destinations.destroy', ['destination' => $destination->name])" form-method="DELETE"
                                            :confirm="sprintf(__('Delete destination %s? Its archives stay where they are.'), $destination->name)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>

</x-ap-page>
