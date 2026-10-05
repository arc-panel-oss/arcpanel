{{-- Icon from the sprite (<x-icons/>). Direction icons are mirrored in RTL. --}}
@props(['name', 'size' => null])
@php
    $directional = in_array($name, ['chevron-right', 'chevron-left', 'arrow', 'return', 'logout', 'send', 'external'], true);
    $classes = trim('ic'.($size ? ' ic-'.$size : '').($name === 'more' ? ' ic-dots' : '').($directional ? ' ap-icon--dir' : ''));
@endphp
<svg {{ $attributes->merge(['class' => $classes]) }} aria-hidden="true"><use href="#i-{{ $name }}"></use></svg>
