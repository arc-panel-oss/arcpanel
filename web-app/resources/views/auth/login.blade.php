@extends('layouts.guest')

@section('title', __('Login'))
@section('heading', sprintf(__('Welcome to %s'), $appName))

@section('content')
<form class="ap-form" action="{{ route('login.store') }}" method="post">
    @csrf
    <div class="ap-field">
        <label class="ap-label" for="user">{{ __('Username') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-user"></use></svg>
            <input class="ap-input" id="user" name="user" type="text" autocomplete="username"
                   value="{{ old('user') }}" maxlength="30" required @unless (old('user')) autofocus @endunless>
        </div>
    </div>
    <div class="ap-field">
        <label class="ap-label" for="password">
            <span>{{ __('Password') }}</span>
            @if ($passwordReset)
                <a href="{{ route('reset') }}" style="font-weight: 500; font-size: 13px">{{ __('Forgot Password') }}</a>
            @endif
        </label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-lock"></use></svg>
            <input class="ap-input" id="password" name="password" type="password"
                   autocomplete="current-password" required @if (old('user')) autofocus @endif>
        </div>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">
        {{ __('Login') }}<svg class="ic" aria-hidden="true"><use href="#i-arrow"></use></svg>
    </button>
</form>
@endsection
