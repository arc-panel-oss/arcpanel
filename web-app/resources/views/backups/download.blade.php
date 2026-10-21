{{-- A remote archive is fetched to the server before it can be downloaded; a link only asks, the button posts (E39 W5 L22). --}}
<x-ap-page :title="__('Download')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.archives.index', ['user' => $user])], ['label' => $archive->name]]"
    :eyebrow="__('Owner').': '.$user.' · '.$archive->date.' '.$archive->time">
    <x-ap-card :title="$archive->name" icon="download">
        <p>{{ __('This backup is stored on a remote backup host. ArcPanel first copies it to the server and sends you a notification when it can be downloaded.') }}</p>
        <x-ap-form :action="route('backups.archives.fetch', ['user' => $user, 'backup' => $archive->name])">
            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="download">{{ __('Fetch the backup') }}</x-ap-button>
                <x-ap-button :href="route('backups.archives.index', ['user' => $user])" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
