{{-- One panel of x-ap-tabs. --}}
@props(['name'])
<section {{ $attributes->merge(['class' => 'ap-tabpanel']) }} role="tabpanel" id="{{ $name }}" aria-labelledby="tab-{{ $name }}" tabindex="0">
    {{ $slot }}
</section>
