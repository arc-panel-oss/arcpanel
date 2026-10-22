{{--
    Add a backup destination with a wizard (type, connection, options), or
    change one (E48 spec §8.2). Saving tests the connection; the result
    shows on the edit page with the SFTP host key to confirm.
--}}
@use('App\Http\Controllers\Server\Backups\DestinationController')
@use('App\Services\Backups\BackupDestination')
@use('App\Services\Backups\BackupJobRun')
@php
    $editing = $destination !== null;
    $canManage ??= true;
    $jobs ??= [];
    $title = $editing ? sprintf(__('Destination %s'), $destination->name) : __('Add destination');
    $crumbs = [['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')],
        ['label' => __('Destinations'), 'url' => route('server.backups.destinations')], ['label' => $editing ? $destination->name : __('Add destination')]];
    $driver = $editing ? $destination->driver : (string) old('driver', 'local');
    $oldFields = (array) old('fields', []);
    $values = $editing ? ($oldFields ?: $destination->fields + ['DESCRIPTION' => $destination->description, 'BWLIMIT' => $destination->bwlimit]) : $oldFields;
    $encrypt = $editing ? $destination->encrypt : (old('fields.ENCRYPT') !== null ? old('fields.ENCRYPT') === 'yes' : true);
    $stateLabel = ['ok' => __('Working'), 'failing' => __('Failing'), 'unchecked' => __('Not checked')];
@endphp
<x-ap-page :title="$title" :crumbs="$crumbs">
    @if ($editing)
        <x-slot:actions>
            @if ($canManage)
                <x-ap-button icon="refresh" :form-action="route('server.backups.destinations.check', ['destination' => $destination->name])">{{ __('Test connection') }}</x-ap-button>
            @endif
        </x-slot:actions>
    @endif

    @include('server.backups.partials.check-result')

    @if ($editing)
        <x-ap-card :title="__('State')" icon="activity">
            <dl class="ap-dl">
                <dt>{{ __('Type') }}</dt><dd><span class="ap-row"><x-ap-icon :name="BackupDestination::driverIcon($destination->driver)" size="sm" />{{ BackupDestination::driverLabel($destination->driver) }}</span></dd>
                <dt>{{ __('State') }}</dt><dd><x-ap-badge :tone="BackupJobRun::statusTone($destination->state)">{{ $stateLabel[$destination->state] ?? $destination->state }}</x-ap-badge>
                    @if ($destination->checked !== '') <span class="muted">{{ sprintf(__('Checked %s'), $destination->checked) }}</span>@endif</dd>
                @if ($destination->hostKey !== '')
                    <dt>{{ __('Host key') }}</dt><dd class="mono">{{ $destination->hostKey }}</dd>
                @endif
                <dt>{{ __('Jobs') }}</dt>
                <dd>
                    @forelse ($jobs as $job)
                        <a href="{{ route('server.backups.jobs.edit', ['job' => $job->id]) }}">{{ $job->name }}</a>@if (! $loop->last), @endif
                    @empty
                        <span class="muted">{{ __('No job sends backups here yet.') }}</span>
                    @endforelse
                </dd>
            </dl>
        </x-ap-card>
    @endif

    <x-ap-form :action="$editing ? route('server.backups.destinations.update', ['destination' => $destination->name]) : route('server.backups.destinations.store')"
        :method="$editing ? 'PUT' : 'POST'" enctype="multipart/form-data" focus-first>
        <div x-data="{ driver: @js($driver) }" class="ap-stack">
            @if (! $editing)
                <x-ap-wizard :steps="[__('Type'), __('Connection'), __('Options')]" :submit-label="__('Add and test')">
                    <fieldset data-step class="ap-fieldset-plain" x-on:change="if ($event.target.name === 'driver') driver = $event.target.value">
                        <x-ap-card-picker name="driver" :label="__('Where should the backups go?')" :value="$driver"
                            :options="array_map(fn ($card) => ['title' => $card['title'], 'description' => $card['description'], 'icon' => $card['icon']], DestinationController::driverCards())" />
                    </fieldset>
                    <fieldset data-step class="ap-fieldset-plain">
                        <x-ap-card :title="__('Connection')" icon="plug">
                            @foreach (array_keys(BackupDestination::DRIVERS) as $each)
                                @include('server.backups.destinations.fields', ['driver' => $each, 'values' => $each === $driver ? $values : [], 'stored' => [], 'picker' => true])
                            @endforeach
                        </x-ap-card>
                    </fieldset>
                    <fieldset data-step class="ap-fieldset-plain">
                        @include('server.backups.destinations.options', ['editing' => false])
                    </fieldset>
                </x-ap-wizard>
            @else
                <fieldset class="ap-fieldset-plain ap-stack" @disabled(! $canManage)>
                    <x-ap-card :title="__('Connection')" icon="plug">
                        @include('server.backups.destinations.fields', ['driver' => $driver, 'values' => $values, 'stored' => $destination->secrets, 'picker' => false])
                    </x-ap-card>
                    @include('server.backups.destinations.options', ['editing' => true])
                </fieldset>
                @if ($canManage)
                    <div class="ap-form-actions">
                        <x-ap-button :href="route('server.backups.destinations')">{{ __('Cancel') }}</x-ap-button>
                        <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save and test') }}</x-ap-button>
                    </div>
                @endif
            @endif
        </div>
    </x-ap-form>

    @if ($editing && $destination->encrypt && $canManage)
        <x-ap-card :title="__('Recovery kit')" icon="key">
            <p>{{ __('Archives and snapshots on this destination are encrypted with its own key. The recovery kit holds that key: keep it outside this server, for example in a password manager. A new server needs it to read the backups.') }}</p>
            @if ($destination->needsKit())
                <x-ap-alert tone="warn">{{ __('The recovery kit of this destination was never downloaded.') }}</x-ap-alert>
            @else
                <p class="muted">{{ sprintf(__('Last downloaded %s.'), $destination->kit) }}</p>
            @endif
            <form method="post" action="{{ route('server.backups.destinations.kit', ['destination' => $destination->name]) }}">
                @csrf
                <x-ap-button type="submit" icon="download">{{ __('Download recovery kit') }}</x-ap-button>
                <small class="muted">{{ __('You confirm your password first.') }}</small>
            </form>
        </x-ap-card>
    @endif

    @if ($editing && $canManage)
        <x-ap-card :title="__('Delete destination')" icon="trash">
            @if ($destination->jobs !== [])
                <p class="muted">{{ __('Remove the destination from its jobs before you delete it.') }}</p>
            @else
                <p>{{ __('The destination and its credentials are removed from this server. Its archives stay where they are.') }}</p>
                <x-ap-button icon="trash" :form-action="route('server.backups.destinations.destroy', ['destination' => $destination->name])" form-method="DELETE"
                    :confirm="sprintf(__('Delete destination %s?'), $destination->name)">{{ __('Delete') }}</x-ap-button>
            @endif
        </x-ap-card>
    @endif
</x-ap-page>
