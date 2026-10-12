{{-- One restic snapshot: restore everything or one object (web/list/backup/incremental?snapshot=, E7 batch 1 spec §10.2). --}}
@php
    $params = ['user' => $user, 'snapshot' => $snapshot->shortId];
    $sections = [
        'web' => __('Web Domains'),
        'dns' => __('DNS Domains'),
        'mail' => __('Mail Domains'),
        'db' => __('Databases'),
        'cron' => __('Cron Jobs'),
    ];
    // No DNS or mail card on a server without them, unless the snapshot holds some
    $sections = array_filter($sections, fn ($type) => ($systems[$type] ?? true) || $content->items[$type] !== [], ARRAY_FILTER_USE_KEY);
@endphp
<x-ap-page :title="sprintf(__('Snapshot %s'), $snapshot->shortId)"
    :crumbs="[['label' => __('Backups'), 'url' => route('backups.archives.index', ['user' => $user])], ['label' => __('Snapshots'), 'url' => route('backups.snapshots.index', ['user' => $user])], ['label' => $snapshot->shortId]]"
    :eyebrow="__('Owner').': '.$user.' · '.$snapshot->label()">
    <x-slot:actions>
        @if ($browse)
            <x-ap-button :href="route('files.show', ['user' => $user, 'snapshot' => $snapshot->shortId])" icon="folder">{{ __('Browse Files') }}</x-ap-button>
        @endif
        @if ($can['restore'])
            <x-ap-button :form-action="route('backups.snapshots.restore', $params)" variant="primary" icon="history"
                :confirm="$content->items['mail'] !== []
                    ? __('Restore everything in this snapshot? Current websites, mail, databases and files are replaced.')
                    : __('Restore everything in this snapshot? Current websites, databases and files are replaced.')">{{ __('Restore All') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @foreach ($sections as $type => $label)
        <x-ap-card :title="$label" :flush="true">
            @if ($content->items[$type] === [])
                <p class="muted ap-card-pad">{{ __('Nothing of this kind in the snapshot.') }}</p>
            @else
                <table class="ap-table">
                    <caption class="sr-only">{{ $label }}</caption>
                    <tbody>
                        @foreach ($type === 'cron' ? array_slice($content->items[$type], 0, 1) : $content->items[$type] as $item)
                            <tr>
                                <td class="mono">{{ $type === 'cron' ? __('All cron jobs') : $item }}</td>
                                <td class="col-actions">
                                    @if ($can['restore'])
                                        <form method="post" action="{{ route('backups.snapshots.restore', $params) }}" class="ap-inline-form"
                                            data-confirm="{{ sprintf(__('Restore %s from the snapshot?'), $type === 'cron' ? __('All cron jobs') : $item) }}">
                                            @csrf
                                            <input type="hidden" name="type" value="{{ $type }}">
                                            @if ($type !== 'cron')<input type="hidden" name="object" value="{{ $item }}">@endif
                                            <button type="submit" class="ap-btn ap-btn-sm"><x-ap-icon name="history" />{{ __('Restore') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ap-card>
    @endforeach
</x-ap-page>
