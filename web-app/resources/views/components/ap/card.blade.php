{{-- Section card with head (icon, title, actions), body and optional footer; the title names the region. --}}
@props(['title' => null, 'icon' => null, 'flush' => false, 'level' => 2, 'actions' => null, 'footer' => null])
@php($titleId = $title ? 'card-'.\Illuminate\Support\Str::random(8) : null)
<section {{ $attributes->merge(['class' => 'ap-card'.($flush ? ' ap-card-flush' : '')]) }} @if ($titleId) aria-labelledby="{{ $titleId }}" @endif>
    @if ($title || $actions)
        <div class="ap-card-head">
            @if ($title)
                <div class="ap-card-title">
                    @if ($icon)<x-ap-icon :name="$icon" />@endif
                    <h{{ $level }} id="{{ $titleId }}">{{ $title }}</h{{ $level }}>
                </div>
            @endif
            @if ($actions)
                <div class="ap-actions">{{ $actions }}</div>
            @endif
        </div>
    @endif
    {{ $slot }}
    @if ($footer)
        <div class="ap-card-foot">{{ $footer }}</div>
    @endif
</section>
