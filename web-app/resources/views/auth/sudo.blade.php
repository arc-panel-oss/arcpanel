@extends('layouts.guest')

{{-- Sudo mode: the password confirmation before a sensitive change of one's own account (E39 W2 spec §7). --}}
@section('title', __('Confirm your password'))
@section('heading', __('Confirm your password'))

@section('content')
@include('auth.partials.too-many')
<form class="ap-form" action="{{ route('sudo.store') }}" method="post">
    @csrf
    <p>{{ __('Confirm your password to continue.') }}</p>
    <input type="hidden" name="user" value="{{ $user }}" autocomplete="username">
    <div class="ap-field">
        <label class="ap-label" for="password">{{ __('Password') }}</label>
        <div class="ap-input-affix">
            <svg class="ic" aria-hidden="true"><use href="#i-lock"></use></svg>
            <input class="ap-input" id="password" name="password" type="password" autocomplete="current-password" maxlength="256" required autofocus>
        </div>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" style="height: 44px">{{ __('Confirm') }}</button>
    <a href="{{ session('sudo.intended', route('dashboard')) }}" class="ap-btn ap-btn-block">{{ __('Back') }}</a>
</form>
@endsection
