{{--
    Result of a connection test (E48 spec §4.4, §8.2): ok with the latency,
    or the error; a first SFTP test shows the host key to confirm.
--}}
@php($check = session('check'))
@if (is_array($check))
    @if ($check['ok'])
        <x-ap-alert tone="ok" :title="sprintf(__('Destination %s works'), $check['name'])" data-check-result="ok">
            {{ $check['latency'] !== '' ? sprintf(__('Connected, wrote and read a test file in %s ms.'), $check['latency']) : __('Connected, wrote and read a test file.') }}
        </x-ap-alert>
    @elseif ($check['hostKey'] !== '')
        <x-ap-alert tone="warn" :title="__('Confirm the host key of the server')" data-check-result="host-key">
            <p>{{ __('This is the first connection to the server. Compare the fingerprint with the one its administrator gives you before you trust it:') }}</p>
            <p><code class="mono">{{ $check['hostKey'] }}</code></p>
            <x-slot:actions>
                <form method="post" action="{{ route('server.backups.destinations.check', ['destination' => $check['name']]) }}">
                    @csrf
                    <input type="hidden" name="accept" value="{{ $check['hostKey'] }}">
                    <x-ap-button type="submit" variant="primary" icon="shield-check">{{ __('Trust this key and test') }}</x-ap-button>
                </form>
            </x-slot:actions>
        </x-ap-alert>
    @else
        <x-ap-alert tone="danger" :title="sprintf(__('Destination %s does not work'), $check['name'])" data-check-result="failed">
            {{ $check['error'] !== '' ? $check['error'] : __('The connection test failed.') }}
            {{ __('Jobs skip it and keep their other destinations until it works again.') }}
        </x-ap-alert>
    @endif
@endif
