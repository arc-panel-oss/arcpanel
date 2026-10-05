{{-- Loading placeholder that mirrors a list or table: rows of a circle and two lines. --}}
@props(['rows' => 3, 'label' => null])
<ul {{ $attributes->merge(['class' => 'ap-list ap-skeleton']) }} aria-busy="true" aria-label="{{ $label ?? __('Loading') }}">
    @for ($i = 0; $i < $rows; $i++)
        <li aria-hidden="true">
            <span class="ap-skel ap-skel-circle"></span>
            <div class="ap-list-main ap-skel-lines"><span class="ap-skel ap-skel-line"></span><span class="ap-skel ap-skel-line is-short"></span></div>
            <span class="ap-skel ap-skel-pill"></span>
        </li>
    @endfor
</ul>
