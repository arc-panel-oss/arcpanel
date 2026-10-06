{{--
    Quota or resource usage (docs/design/05 §5): warn from 80 %, danger from
    95 %; an unlimited quota shows a dashed, empty track. value/limit are
    numbers; value-label/limit-label are what the user reads (sizes, counts);
    note is a small line under the bar.
--}}
@props(['label', 'value' => 0, 'limit' => null, 'valueLabel' => null, 'limitLabel' => null, 'unlimited' => false, 'href' => null, 'note' => null])
@php
    $unlimited = $unlimited || $limit === null || $limit === 'unlimited';
    $percent = $unlimited ? 0 : ((float) $limit > 0 ? min(100, (int) round((float) $value / (float) $limit * 100)) : ((float) $value > 0 ? 100 : 0));
    $tone = $unlimited ? 'is-unlimited' : ($percent >= 95 ? 'is-danger' : ($percent >= 80 ? 'is-warn' : ''));
    $current = (string) ($valueLabel ?? $value);
    $shown = $unlimited && $current === __('Unlimited') ? $current : $current.' / '.($unlimited ? __('Unlimited') : ($limitLabel ?? $limit));
@endphp
<div {{ $attributes->merge(['class' => trim('ap-usage '.$tone)]) }}>
    <div class="ap-usage-head">@if ($href)<a href="{{ $href }}">{{ $label }}</a>@else<span>{{ $label }}</span>@endif<span>{{ $shown }}</span></div>
    <div class="ap-bar" role="meter" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-valuetext="{{ $shown }}">
        <span style="width: {{ $percent }}%"></span>
    </div>
    @if (filled($note))
        <span class="ap-usage-note">{{ $note }}</span>
    @endif
</div>
