{{-- Error page outside the shell (guests, unknown routes). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="nocturne">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $code }} · {{ $message }}</title>
    <link rel="stylesheet" href="@apAsset('app.css')">
</head>
<body>
<x-icons />
<div class="ap-root">
    <main class="ap-content" id="main">
        <x-ap-empty icon="alert" :title="$code.' · '.$message">
            <x-slot:actions><a class="ap-btn" href="/">{{ __('Home') }}</a></x-slot:actions>
        </x-ap-empty>
    </main>
</div>
</body>
</html>
