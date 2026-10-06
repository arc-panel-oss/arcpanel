{{-- Web terminal (web/list/terminal, E7 batch 2 spec §10.4). --}}
<x-ap-page :title="__('Web terminal')" :crumbs="[['label' => __('Web terminal')]]"
    :description="__('A shell on this server, as your account.')">
    <x-ap-card :flush="true">
        <div class="ap-terminal" x-data="apTerminal({ messages: { error: {{ json_encode(__('Connection error.')) }}, closed: {{ json_encode(__('Connection closed.')) }} } })">
            <div class="ap-terminal-screen" x-ref="screen" role="application" aria-label="{{ __('Web terminal') }}"></div>
            <noscript><p class="ap-hint">{{ __('The terminal needs JavaScript.') }}</p></noscript>
        </div>
    </x-ap-card>
</x-ap-page>
