{{-- phpMyAdmin single sign-on: the token is posted, never put in a URL, so no access log or history keeps it (E39 W2 spec D9). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('phpMyAdmin') }}</title>
</head>
<body>
<form id="ap-sso" action="{{ $action }}" method="post">
    @foreach ($fields as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <noscript><button type="submit">{{ __('phpMyAdmin') }}</button></noscript>
</form>
<script>document.getElementById('ap-sso').submit();</script>
</body>
</html>
