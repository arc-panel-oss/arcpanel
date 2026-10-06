@extends('layouts.guest')

{{-- Password reset, step 1: user name and contact e-mail (web/reset, E7 batch 3 spec §11). --}}
@section('title', __('Forgot Password'))
@section('heading', __('Forgot Password'))

@section('content')
@include('auth.partials.too-many')
<form class="ap-form" action="{{ route('reset.request') }}" method="post">
    @csrf
    <div class="ap-field">
        <label class="ap-label" for="user">{{ __('Username') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-user"></use></svg>
            <input class="ap-input" id="user" name="user" type="text" autocomplete="username" value="{{ old('user') }}" maxlength="30" required autofocus>
        </div>
    </div>
    <div class="ap-field">
        <label class="ap-label" for="email">{{ __('Email') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-mail"></use></svg>
            <input class="ap-input" id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" maxlength="254" required>
        </div>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">{{ __('Submit') }}</button>
    <a href="{{ route('login') }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
</form>
@endsection
