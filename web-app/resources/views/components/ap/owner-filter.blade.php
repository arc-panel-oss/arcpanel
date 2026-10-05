{{-- Owner filter of a list toolbar (admin and reseller lists): a select that
     reloads the page with ?owner=, and a chip to clear it. --}}
@props(['route', 'params' => [], 'owners', 'owner' => ''])
<form method="get" action="{{ route($route, $params) }}" class="ap-owner-filter">
    <label class="sr-only" for="owner-filter">{{ __('Owner') }}</label>
    <div class="ap-select-wrap">
        <select class="ap-select ap-select-sm" id="owner-filter" name="owner" onchange="this.form.requestSubmit()">
            <option value="">{{ __('All owners') }}</option>
            @foreach ($owners as $name)
                <option value="{{ $name }}" @selected($name === $owner)>{{ $name }}</option>
            @endforeach
        </select>
        <x-ap-icon name="chevron-down" size="sm" />
    </div>
    <noscript><button type="submit" class="ap-btn ap-btn-sm">{{ __('Filter') }}</button></noscript>
</form>
@if ($owner !== '')
    <x-ap-chip :active="true" :remove-href="route($route, $params)" :remove-label="__('Clear owner filter')">{{ __('Owner') }}: {{ $owner }}</x-ap-chip>
@endif
