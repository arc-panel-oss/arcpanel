{{-- One tar backup: restore everything or selected objects (web/list/backup?backup=, E7 batch 1 spec §10.2). --}}
@php
    $params = ['user' => $user, 'backup' => $archive->name];
    $sections = [
        'web' => __('Web Domains'),
        'dns' => __('DNS Domains'),
        'mail' => __('Mail Domains'),
        'db' => __('Databases'),
        'cron' => __('Cron Jobs'),
        'udir' => __('User Directories'),
    ];
    // No DNS or mail card on a server without them, unless the backup holds some
    $sections = array_filter($sections, fn ($type) => ($systems[$type] ?? true) || $archive->items[$type] !== [], ARRAY_FILTER_USE_KEY);
@endphp
<x-ap-page :title="$archive->name" :crumbs="[['label' => __('Backups'), 'url' => route('backups.overview', ['user' => $user])], ['label' => __('Archives'), 'url' => route('backups.archives.index', ['user' => $user])], ['label' => $archive->name]]"
    :eyebrow="__('Owner').': '.$user.' · '.$archive->date.' '.$archive->time">
    <x-slot:actions>
        @if ($can['download'])
            <x-ap-button :href="route('backups.archives.download', $params)" icon="download">{{ __('Download') }}</x-ap-button>
        @endif
        @if ($can['restore'])
            <x-ap-button :form-action="route('backups.archives.restore', $params)" variant="primary" icon="history"
                :confirm="$archive->items['mail'] !== []
                    ? __('Restore everything in this backup? Current websites, mail, databases and files are replaced.')
                    : __('Restore everything in this backup? Current websites, databases and files are replaced.')">{{ __('Restore All') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-form :action="route('backups.archives.restore', $params)" id="restore-selected">
        @foreach ($sections as $type => $label)
            <x-ap-card :title="$label" :flush="true">
                @if ($archive->items[$type] === [])
                    <p class="muted ap-card-pad">{{ __('Nothing of this kind in the backup.') }}</p>
                @else
                    <div class="ap-table-wrap">
                        <table class="ap-table">
                            <caption class="sr-only">{{ $label }}</caption>
                            <tbody>
                                @foreach ($type === 'cron' ? array_slice($archive->items[$type], 0, 1) : $archive->items[$type] as $item)
                                    <tr>
                                        <td class="col-check">
                                            @if ($can['restore'])
                                                <label class="ap-check"><input type="checkbox" name="items[{{ $type }}][]" value="{{ $item }}" id="item-{{ $type }}-{{ $loop->index }}"></label>
                                            @endif
                                        </td>
                                        <td><label class="mono" for="item-{{ $type }}-{{ $loop->index }}">{{ $type === 'cron' ? __('All cron jobs') : $item }}</label></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ap-card>
        @endforeach
        @if ($can['restore'])
            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="history">{{ __('Restore Selected') }}</x-ap-button>
                <x-ap-button :href="route('backups.archives.index', ['user' => $user])" variant="ghost">{{ __('Back') }}</x-ap-button>
            </div>
        @endif
    </x-ap-form>
</x-ap-page>
