@extends('layouts.guest')

{{-- Password reset, step 2: the code of the e-mail (web/reset/?action=code, E7 batch 3 spec §11). --}}
@section('title', __('Forgot Password'))
@section('heading', __('Forgot Password'))

@section('content')
<form class="ap-form" action="{{ route('reset.confirm') }}" method="get">
    <div class="ap-field">
        <label class="ap-label" for="user">{{ __('Username') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-user"></use></svg>
            <input class="ap-input" id="user" name="user" type="text" autocomplete="username" value="{{ $user }}" maxlength="30" required @if ($user === '') autofocus @endif>
        </div>
    </div>
    <div class="ap-field">
        <label class="ap-label" for="code">{{ __('Reset Code') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-key"></use></svg>
            <input class="ap-input" id="code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required @if ($user !== '') autofocus @endif>
        </div>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">{{ __('Confirm') }}</button>
    <a href="{{ route('reset') }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
</form>
@endsection
