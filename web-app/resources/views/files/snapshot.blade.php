{{-- A folder of a restic snapshot in the file manager (E7 batch 1 spec §10.3); the island takes over with Restore. --}}
<x-ap-page :title="sprintf(__('Snapshot %s'), $snapshot->shortId)"
    :crumbs="[['label' => __('Backups'), 'url' => route('backups.archives.index', ['user' => $user])], ['label' => __('Snapshots'), 'url' => route('backups.snapshots.index', ['user' => $user])], ['label' => $snapshot->shortId]]"
    :eyebrow="__('File manager · :user', ['user' => $user])">
    <x-ap-island name="file-manager" :props="$props" class="ap-stack">
        {{-- Without scripts: the folder's entries --}}
        <x-ap-card :title="'~/'.$path->relative()" icon="history" :flush="true">
            @if ($entries === [])
                <x-ap-empty icon="folder" :title="__('This folder is empty.')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <caption class="sr-only">{{ __('Files') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col" class="col-num">{{ __('Size') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td data-label="{{ __('Name') }}">
                                        @if ($entry['dir'] && $entry['validName'])
                                            <a class="mono" href="{{ route('files.show', ['user' => $user, 'snapshot' => $snapshot->shortId, 'path' => $path->child($entry['name'])->relative()]) }}">{{ $entry['name'] }}/</a>
                                        @else
                                            <span class="mono">{{ $entry['name'] }}</span>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('Size') }}" class="col-num mono">{{ $entry['dir'] ? '—' : number_format($entry['size']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
