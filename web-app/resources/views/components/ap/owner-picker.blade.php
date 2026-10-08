{{-- Owner of a new object (add pages of administrators and resellers): a GET
     form that reloads the page for the chosen owner. With $admin, the legacy
     hint to create a standard user account first. --}}
@props(['route', 'params' => [], 'owners' => null, 'owner', 'admin' => false, 'object' => ''])
@if ($owners !== null)
    <x-ap-card>
        <form method="get" action="{{ route($route, $params) }}" class="ap-row ap-owner-pick">
            {{-- The page reloads as soon as another owner is chosen; the button is for pages without scripts --}}
            <x-ap-select name="owner" :label="__('Owner')" :options="array_combine($owners, $owners)" :value="$owner" id="pick-owner" onchange="this.form.requestSubmit()" />
            <x-ap-button type="submit" size="sm" class="ap-no-js">{{ __('Use this owner') }}</x-ap-button>
        </form>
    </x-ap-card>
@endif
@if ($admin)
    <x-ap-alert tone="warn">
        {{ sprintf(__('It is strongly advised to create a standard user account before adding %s to the server due to the increased privileges the admin account possesses and potential security risks.'), $object) }}
        @if (\Illuminate\Support\Facades\Route::has('users.create'))
            <a href="{{ route('users.create') }}">{{ __('Add User') }}</a>
        @endif
    </x-ap-alert>
@endif
