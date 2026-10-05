{{-- 404 inside the shell for a logged-in user; objects outside the owner scope also end here. --}}
@if (app()->bound(\App\Auth\Principal::class) && request()->route() !== null)
    <x-ap-page :title="__('Page not found')" :crumbs="[['label' => __('Page not found')]]">
        <x-ap-card :flush="true">
            <x-ap-empty icon="search" :title="__('This page does not exist.')">
                <x-slot:actions><x-ap-button :href="route('dashboard')" icon="grid">{{ __('Dashboard') }}</x-ap-button></x-slot:actions>
            </x-ap-empty>
        </x-ap-card>
    </x-ap-page>
@else
    @include('errors.plain', ['code' => 404, 'message' => __('Page not found')])
@endif
