{{-- Server settings: Backups. Since E48 the Backups hub holds destinations, jobs and their settings (spec §8.2). --}}
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Backups')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('Backups')" icon="archive">
        <p>{{ __('Backups have their own pages: destinations where the backups go, jobs with their schedules and retention, the activity of every run, and the settings of compression and load.') }}</p>
        <div class="ap-row">
            <x-ap-button :href="route('server.backups')" variant="primary" icon="arrow">{{ __('Open Backups') }}</x-ap-button>
            <x-ap-button :href="route('server.backups.destinations')" icon="disk">{{ __('Destinations') }}</x-ap-button>
            <x-ap-button :href="route('server.backups.jobs')" icon="clock">{{ __('Schedules') }}</x-ap-button>
            <x-ap-button :href="route('server.backups.settings')" icon="sliders">{{ __('Settings') }}</x-ap-button>
        </div>
    </x-ap-card>
</x-ap-page>
