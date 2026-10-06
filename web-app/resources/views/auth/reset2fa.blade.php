@extends('layouts.guest')

{{-- 2FA reset with the recovery code (web/reset2fa, E7 batch 3 spec §11). --}}
@section('title', $unlocked ? __('Account Unlocked') : __('Unlock Account'))
@section('heading', $unlocked ? __('Account Unlocked') : __('Unlock Account'))

@section('content')
@if ($unlocked)
    <div class="ap-alert is-ok" role="status">
        <svg class="ic" aria-hidden="true"><use href="#i-check-circle"></use></svg>
        <div class="ap-alert-body"><p>{{ __('Two-factor authentication is now turned off for your account.') }} {{ __('You may now proceed to log in.') }}</p></div>
    </div>
    <a href="{{ route('login') }}" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">{{ __('Log in') }}</a>
@else
    @include('auth.partials.too-many')
    <form class="ap-form" action="{{ route('reset2fa.store') }}" method="post">
        @csrf
        <div class="ap-field">
            <label class="ap-label" for="user">{{ __('Username') }}</label>
            <div class="ap-input-affix">
                <svg class="ic" aria-hidden="true"><use href="#i-user"></use></svg>
                <input class="ap-input" id="user" name="user" type="text" autocomplete="username" value="{{ old('user') }}" maxlength="30" required autofocus>
            </div>
        </div>
        <div class="ap-field">
            <label class="ap-label" for="twofa">{{ __('2FA Reset Code') }}</label>
            <div class="ap-input-affix">
                <svg class="ic" aria-hidden="true"><use href="#i-key"></use></svg>
                <input class="ap-input" id="twofa" name="twofa" type="text" autocomplete="off" maxlength="128" required>
            </div>
        </div>
        <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">{{ __('Submit') }}</button>
        <a href="{{ route('login') }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
    </form>
@endif
@endsection
