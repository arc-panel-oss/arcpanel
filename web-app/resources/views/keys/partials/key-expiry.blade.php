{{-- The expiry of an access key ($key) with its state (E43 B6): "Expires soon" within 14 days, "Expired" after it. --}}
@if ($key->expires === null)
    <span class="muted">{{ __('Never') }}</span>
@else
    <span class="ap-row ap-key-expiry">
        <span class="nowrap">{{ \Carbon\CarbonImmutable::instance($key->expires)->utc()->format('Y-m-d H:i') }} UTC</span>
        @if ($key->expired())
            <x-ap-badge tone="danger">{{ __('Expired') }}</x-ap-badge>
        @elseif ($key->expiresSoon())
            <x-ap-badge tone="warn">{{ __('Expires soon') }}</x-ap-badge>
        @endif
    </span>
@endif
