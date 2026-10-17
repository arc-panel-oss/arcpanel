{{-- Create wizard of an app process (E42 spec §9); the app-processes island renders the steps. --}}
<x-ap-page :title="__('Add process')" :crumbs="[['label' => __('App processes'), 'url' => route('app-processes.index', ['user' => $user])], ['label' => __('Add process')]]"
    :description="__('Keep a server or a worker of one of your web domains running.')">
    <x-ap-island name="app-processes" :props="$props" class="ap-stack">
        <x-ap-alert tone="info">{{ __('The wizard needs JavaScript.') }}</x-ap-alert>
    </x-ap-island>
</x-ap-page>
