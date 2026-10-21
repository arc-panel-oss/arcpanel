@extends('layouts.guest')

@section('title', __('Two-factor Authentication'))
@section('heading', __('Two-factor Authentication'))

@section('content')
@include('auth.partials.too-many')
<form class="ap-form" action="{{ route('login.2fa.store') }}" method="post">
    @csrf
    @if ($recovery)
        {{-- A recovery code stands in for the app's code once (E39 W5 §11.3) --}}
        <div class="ap-field">
            <label class="ap-label" for="recovery_code">
                <span>{{ __('Recovery code') }}</span>
                <a href="{{ route('login.2fa') }}" style="font-weight: 500; font-size: 13px">{{ __('Use the app') }}</a>
            </label>
            <div class="ap-input-affix">
                <svg class="ic" aria-hidden="true"><use href="#i-key"></use></svg>
                <input class="ap-input" id="recovery_code" name="recovery_code" type="text" autocomplete="off"
                       autocapitalize="characters" spellcheck="false" maxlength="24" required autofocus>
            </div>
        </div>
    @else
        <div class="ap-field">
            <label class="ap-label" for="twofa">
                <span>{{ __('2FA Token') }}</span>
                <a href="{{ route('login.2fa', ['recovery' => 1]) }}" style="font-weight: 500; font-size: 13px">{{ __('Use a recovery code') }}</a>
            </label>
            <div class="ap-input-affix">
                <svg class="ic" aria-hidden="true"><use href="#i-key"></use></svg>
                <input class="ap-input" id="twofa" name="twofa" type="text" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="9" required autofocus>
            </div>
        </div>
    @endif
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">
        {{ __('Login') }}<svg class="ic" aria-hidden="true"><use href="#i-arrow"></use></svg>
    </button>
    <a href="{{ route('login') }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
</form>
@endsection
