<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr' }}" data-theme="nocturne">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $appName }}</title>
    <link rel="icon" href="/images/favicon.png" type="image/png">
    <link rel="stylesheet" href="{{ asset('build/arc.min.css') }}">
</head>
<body>
<x-icons />
<div class="ap-root">
    <div class="ap-login">
        <section class="ap-login-art" aria-hidden="true">
            <svg class="orbits" viewBox="0 0 800 520" preserveAspectRatio="xMidYMax slice">
                <circle class="o" cx="400" cy="620" r="170"></circle>
                <circle class="o-dash" cx="400" cy="620" r="250"></circle>
                <circle class="o" cx="400" cy="620" r="330"></circle>
                <circle class="o-dash" cx="400" cy="620" r="410"></circle>
                <circle class="o" cx="400" cy="620" r="490"></circle>
                <path class="o-accent" d="M110 620 A290 290 0 0 1 625 437" pathLength="100"></path>
                <circle class="node" cx="625" cy="437" r="6"></circle>
                <circle class="node" cx="196" cy="384" r="4.5"></circle>
                <circle class="node" cx="560" cy="250" r="4.5"></circle>
                <circle class="star" cx="652" cy="148" r="4"></circle>
                <circle class="star" cx="168" cy="196" r="2.2"></circle>
                <circle class="star" cx="720" cy="300" r="1.8"></circle>
            </svg>
            <div><x-logo /></div>
        </section>
        <section class="ap-login-panel">
            <div class="ap-login-card">
                <header>
                    <span class="ap-login-host"><span class="dot"></span>{{ request()->getHost() }}</span>
                    <h1>@yield('heading')</h1>
                </header>

                @if (session('error'))
                    <div class="ap-alert is-danger" role="alert">
                        <svg class="ic" aria-hidden="true"><use href="#i-alert"></use></svg>
                        <div class="ap-alert-body"><p>{{ session('error') }}</p></div>
                    </div>
                @endif

                @yield('content')
            </div>
            <footer class="ap-login-foot">
                <span>ArcPanel · Based on HestiaCP</span>
            </footer>
        </section>
    </div>
</div>
</body>
</html>
