{{-- Owner picker of a page that belongs to one account (keys, logs, statistics):
     GET route?owner=NAME, shown when there is more than one owner to pick. --}}
@props(['route', 'owners' => null, 'owner' => '', 'label' => null, 'empty' => null])
@if ($owners !== null && count($owners) > 1)
    <form method="get" action="{{ route($route) }}" {{ $attributes->merge(['class' => 'ap-row ap-owner-pick']) }}>
        <x-ap-select name="owner" :label="__('Owner')" :options="($empty !== null ? ['' => $empty] : []) + array_combine($owners, $owners)" :value="$owner" id="pick-owner" onchange="this.form.requestSubmit()" />
        <x-ap-button type="submit" size="sm" class="ap-no-js">{{ $label ?? __('Show') }}</x-ap-button>
    </form>
@endif
