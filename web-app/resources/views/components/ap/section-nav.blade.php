{{--
    In-page links to the sections of a long form (apSectionNav): a column
    beside the form on wide screens, a row of links above it on small ones.
    sections: [id => label].
--}}
@props(['sections' => [], 'label'])
<nav {{ $attributes->merge(['class' => 'ap-section-nav']) }} aria-label="{{ $label }}" x-data="apSectionNav()">
    <ul>
        @foreach ($sections as $section => $sectionLabel)
            <li><a href="#{{ $section }}" :aria-current="isCurrent(@js($section)) ? 'true' : null">{{ $sectionLabel }}</a></li>
        @endforeach
    </ul>
</nav>
