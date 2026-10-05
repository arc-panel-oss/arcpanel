{{-- A page could not load because a v-* command failed (E1-S2 spec §10). --}}
@if (app()->bound(\App\Auth\Principal::class) && request()->route() !== null)
    <x-ap-page :title="__('Error')" :crumbs="[['label' => __('Error')]]">
        <x-ap-alert tone="danger" :title="__('The page could not be loaded.')">{{ $message }}</x-ap-alert>
    </x-ap-page>
@else
    @include('errors.plain', ['code' => $status, 'message' => $message])
@endif
