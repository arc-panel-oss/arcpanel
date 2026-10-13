@extends('layouts.guest')

@section('title', __('Two-factor Authentication'))
@section('heading', __('Two-factor Authentication'))

@section('content')
@include('auth.partials.too-many')
<form class="ap-form" action="{{ route('login.2fa.store') }}" method="post">
    @csrf
    <div class="ap-field">
        <label class="ap-label" for="twofa">
            <span>{{ __('2FA Token') }}</span>
            <a href="{{ route('reset2fa') }}" style="font-weight: 500; font-size: 13px">{{ __('Forgot Token') }}</a>
        </label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-key"></use></svg>
            <input class="ap-input" id="twofa" name="twofa" type="text" inputmode="numeric"
                   autocomplete="one-time-code" maxlength="9" required autofocus>
        </div>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">
        {{ __('Login') }}<svg class="ic" aria-hidden="true"><use href="#i-arrow"></use></svg>
    </button>
    <a href="{{ route('login') }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
</form>
@endsection
