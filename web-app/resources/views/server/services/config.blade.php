{{-- Config editor of a service (web/edit/server/<service>, E7 batch 3 spec §9). --}}
<x-ap-page :title="sprintf(__('Configure %s'), $service)" :crumbs="[['label' => __('Server')], ['label' => __('Services'), 'url' => route('server.services')], ['label' => $service]]"
    :description="__('Saving copies the file into place; when the service does not start with it, the previous file is restored.')">
    <x-ap-island name="service-editor" :props="$props" class="ap-service-editor">
        <x-ap-skeleton />
    </x-ap-island>
</x-ap-page>
