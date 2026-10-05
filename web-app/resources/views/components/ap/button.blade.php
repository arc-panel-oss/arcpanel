{{--
    Button (docs/design/05 §5). Variants: default, primary, ghost, danger.
    href renders a link; form-action renders a one-button POST form with CSRF
    (method spoofing with form-method), and confirm asks first (apConfirm).
--}}
@props([
    'variant' => null,
    'size' => null,
    'icon' => null,
    'iconOnly' => false,
    'label' => null,
    'href' => null,
    'type' => 'button',
    'formAction' => null,
    'formMethod' => 'POST',
    'confirm' => null,
    'confirmTitle' => null,
    'confirmAccept' => null,
    'loading' => false,
])
@php
    $classes = trim(implode(' ', array_filter([
        'ap-btn',
        $variant ? 'ap-btn-'.$variant : null,
        $size ? 'ap-btn-'.$size : null,
        $iconOnly ? 'ap-btn-icon' : null,
    ])));
    $confirmAttributes = $confirm ? array_filter([
        'data-confirm' => $confirm,
        'data-confirm-title' => $confirmTitle,
        'data-confirm-accept' => $confirmAccept,
        'data-confirm-danger' => $variant === 'danger' ? '' : null,
    ], fn ($v) => $v !== null) : [];
@endphp
@if ($formAction)
    <form method="post" action="{{ $formAction }}" class="ap-inline-form" @foreach ($confirmAttributes as $key => $value) {{ $key }}="{{ $value }}" @endforeach>
        @csrf
        @if (strtoupper($formMethod) !== 'POST')
            @method($formMethod)
        @endif
        <button type="submit" {{ $attributes->merge(['class' => $classes]) }} @if ($iconOnly && $label) aria-label="{{ $label }}" title="{{ $label }}" @endif>
            @if ($icon)<x-ap-icon :name="$icon" />@endif
            @unless ($iconOnly){{ $slot->isEmpty() ? $label : $slot }}@endunless
        </button>
    </form>
@elseif ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }} @if ($iconOnly && $label) aria-label="{{ $label }}" title="{{ $label }}" @endif>
        @if ($icon)<x-ap-icon :name="$icon" />@endif
        @unless ($iconOnly){{ $slot->isEmpty() ? $label : $slot }}@endunless
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}
        @if ($iconOnly && $label) aria-label="{{ $label }}" title="{{ $label }}" @endif
        @foreach ($confirmAttributes as $key => $value) {{ $key }}="{{ $value }}" @endforeach
        @if ($loading) disabled aria-busy="true" @endif>
        @if ($loading)<span class="ap-spinner" aria-hidden="true"></span>@elseif ($icon)<x-ap-icon :name="$icon" />@endif
        @unless ($iconOnly){{ $slot->isEmpty() ? $label : $slot }}@endunless
    </button>
@endif
