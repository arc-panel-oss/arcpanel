{{-- The rate limit answer of the reset pages (E7 batch 3 spec §11.1). --}}
@if (! empty($tooMany))
    <div class="ap-alert is-danger" role="alert">
        <svg class="ic" aria-hidden="true"><use href="#i-alert"></use></svg>
        <div class="ap-alert-body"><p>{{ $tooMany }}</p></div>
    </div>
@endif
