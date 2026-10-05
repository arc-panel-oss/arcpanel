{{-- 403 inside the shell for a logged-in user (E1-S2 spec §10). --}}
@if (app()->bound(\App\Auth\Principal::class))
    <x-ap-page :title="__('Access denied')" :crumbs="[['label' => __('Access denied')]]">
        <x-ap-card :flush="true">
            <x-ap-empty icon="lock" :title="__('You do not have permission to do this.')">
                <x-slot:actions><x-ap-button :href="route('dashboard')" icon="grid">{{ __('Dashboard') }}</x-ap-button></x-slot:actions>
            </x-ap-empty>
        </x-ap-card>
    </x-ap-page>
@else
    @include('errors.plain', ['code' => 403, 'message' => __('Access denied')])
@endif
