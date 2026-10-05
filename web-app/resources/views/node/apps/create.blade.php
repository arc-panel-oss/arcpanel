{{-- Create wizard of a Node.js app (E14 spec §7); the node-apps island renders the steps. --}}
<x-ap-page :title="__('Create app')" :crumbs="[['label' => __('Node apps'), 'url' => route('node.apps.index', ['user' => $user])], ['label' => __('Create app')]]"
    :description="__('Run a Node.js server for one of your web domains.')">
    <x-ap-island name="node-apps" :props="$props" class="ap-stack">
        <x-ap-alert tone="info">{{ __('The wizard needs JavaScript.') }}</x-ap-alert>
    </x-ap-island>
</x-ap-page>
