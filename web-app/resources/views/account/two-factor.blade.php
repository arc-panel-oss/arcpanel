{{-- Turning two-factor authentication on and recovery codes (E39 W5 spec §11.3): intro, scan, codes. --}}
<x-ap-page :title="__('Two-factor authentication')" :crumbs="[['label' => __('Account'), 'url' => route('account.edit')], ['label' => __('Two-factor authentication')]]"
    :eyebrow="__('Account').': '.$user">

    @if ($step === 'intro')
        <x-ap-card :title="__('Turn on two-factor authentication')" icon="key">
            <p>{{ __('After the password, the sign-in asks for a six-digit code from an authenticator app on your phone, such as Aegis, Google Authenticator or 1Password. You also get ten recovery codes for when the phone is lost.') }}</p>
            <x-ap-form :action="route('account.two-factor.start')">
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="key">{{ __('Set up') }}</x-ap-button>
                    <x-ap-button :href="route('account.edit')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-card>
    @elseif ($step === 'scan')
        <x-ap-card :title="__('Scan the QR code')" icon="key">
            @if ($error)
                <x-ap-alert tone="danger">{{ $error }}</x-ap-alert>
            @endif
            <p>{{ __('Scan the code with the authenticator app, or type the key into it. Then enter the six-digit code the app shows.') }}</p>
            <div class="ap-twofa-setup">
                <img class="ap-twofa-qr" src="{{ $qr }}" width="220" height="220" alt="{{ __('QR code for the authenticator app') }}">
                <div>
                    <p class="ap-label">{{ __('Key') }}</p>
                    <code class="ap-twofa-secret">{{ trim(chunk_split($secret, 4, ' ')) }}</code>
                </div>
            </div>
            <x-ap-form :action="route('account.two-factor.confirm')" focus-first>
                <input type="hidden" name="secret" value="{{ $secret }}">
                <input type="hidden" name="uri" value="{{ $uri }}">
                <div class="ap-form-grid">
                    <x-ap-input name="code" :label="__('Code from the app')" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required />
                </div>
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Turn on') }}</x-ap-button>
                    <x-ap-button :href="route('account.edit')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-card>
    @else
        <x-ap-card :title="__('Recovery codes')" icon="shield-check">
            <x-ap-alert tone="ok">{{ $notice }}</x-ap-alert>
            <p>{{ __('Each code signs you in once when the authenticator app is not at hand. They are shown only now: download or write them down and keep them somewhere safe.') }}</p>
            <ol class="ap-twofa-codes">
                @foreach ($codes as $code)
                    <li><code>{{ $code }}</code></li>
                @endforeach
            </ol>
            <div class="ap-form-actions">
                <x-ap-button :href="$download" icon="download" download="arcpanel-recovery-codes-{{ $user }}.txt">{{ __('Download') }}</x-ap-button>
                <x-ap-button :href="route('account.edit')" variant="primary" icon="check">{{ __('I saved the codes') }}</x-ap-button>
            </div>
        </x-ap-card>
    @endif
</x-ap-page>
