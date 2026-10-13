<?php

use App\Support\PanelHost;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

beforeEach(function () {
    $this->fake = fakeArc(['BACKEND_PORT' => '8083', 'PANEL_HOSTNAMES' => 'panel.example.com, other.example.net']);
    $this->fake->on('v-list-sys-ips', ['json'])->returnsJson([
        '192.0.2.10' => ['NAT' => '198.51.100.7'],
        '2001:db8::5' => ['NAT' => ''],
    ]);
});

function hostAllowed(array $patterns, string $host): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match('{'.$pattern.'}i', $host) === 1) {
            return true;
        }
    }

    return false;
}

it('builds the link host from the hostname and the panel port', function () {
    expect(app(PanelHost::class)->link())->toBe(gethostname().':8083');
});

it('trusts the hostname, the server addresses with their NAT, localhost and PANEL_HOSTNAMES only', function () {
    $patterns = app(PanelHost::class)->trusted();

    foreach ([gethostname(), '192.0.2.10', '198.51.100.7', '[2001:db8::5]', 'localhost', '127.0.0.1', 'panel.example.com', 'OTHER.example.net'] as $host) {
        expect(hostAllowed($patterns, $host))->toBeTrue("{$host} should be trusted");
    }
    foreach (['evil.example', 'panel.example.com.evil.example', 'xpanel.example.com', '192.0.2.100', ''] as $host) {
        expect(hostAllowed($patterns, $host))->toBeFalse("{$host} should not be trusted");
    }
});

it('keeps the hostname and localhost when the address list cannot be read', function () {
    $this->fake->on('v-list-sys-ips', ['json'])->returnsExit(1, 'Error');
    cache()->flush();

    $patterns = app(PanelHost::class)->trusted();

    expect(hostAllowed($patterns, gethostname()))->toBeTrue()
        ->and(hostAllowed($patterns, 'localhost'))->toBeTrue()
        ->and(hostAllowed($patterns, '192.0.2.10'))->toBeFalse();
});

it('registers the trusted hosts middleware with the panel hosts', function () {
    $middleware = app(TrustHosts::class);

    expect($middleware->hosts())->toBe(app(PanelHost::class)->trusted());
});

it('answers 400 to a foreign Host header once trusted hosts apply', function () {
    Request::setTrustedHosts(app(PanelHost::class)->trusted());
    try {
        $request = Request::create('https://evil.example:8083/login');
        expect(fn () => $request->getHost())->toThrow(SuspiciousOperationException::class);
        expect(Request::create('https://'.gethostname().':8083/login')->getHost())->toBe(strtolower(gethostname()));
    } finally {
        Request::setTrustedHosts([]);
    }
});
