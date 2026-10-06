@extends('layouts.guest')

{{-- Password reset, step 3: the new password (web/reset/?action=confirm, the link of the e-mail; E7 batch 3 spec §11). --}}
@section('title', __('Forgot Password'))
@section('heading', __('Forgot Password'))

@section('content')
@include('auth.partials.too-many')
<form class="ap-form" action="{{ route('reset.update') }}" method="post">
    @csrf
    <input type="hidden" name="user" value="{{ $user }}">
    <input type="hidden" name="code" value="{{ $code }}">
    <div class="ap-field">
        <label class="ap-label" for="password">{{ __('New Password') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-lock"></use></svg>
            <input class="ap-input" id="password" name="password" type="password" autocomplete="new-password" maxlength="256" required autofocus>
        </div>
    </div>
    <div class="ap-field">
        <label class="ap-label" for="password_confirm">{{ __('Confirm Password') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-lock"></use></svg>
            <input class="ap-input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" maxlength="256" required>
        </div>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">{{ __('Reset') }}</button>
    <a href="{{ route('reset') }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
</form>
@endsection
