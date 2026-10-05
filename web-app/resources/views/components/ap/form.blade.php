{{--
    Form with CSRF, method spoofing and the focusFirst plugin hook. Works
    without JavaScript; confirm asks before submitting (apConfirm).
--}}
@props(['action', 'method' => 'POST', 'confirm' => null, 'focusFirst' => false, 'files' => false])
<form {{ $attributes->merge(['class' => 'ap-form']) }} action="{{ $action }}" method="{{ strtoupper($method) === 'GET' ? 'get' : 'post' }}"
    @if ($files) enctype="multipart/form-data" @endif
    @if ($confirm) data-confirm="{{ $confirm }}" @endif
    @if ($focusFirst) data-focus-first @endif>
    @unless (strtoupper($method) === 'GET')
        @csrf
        @unless (strtoupper($method) === 'POST')
            @method(strtoupper($method))
        @endunless
    @endunless
    {{ $slot }}
</form>
