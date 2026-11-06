{{-- Doctor (E17 spec §5): the domains of an account (and of its customers on its own page) with their scores; Run now for all of them. --}}
<x-ap-page :title="__('Doctor')" :crumbs="[['label' => __('Account')], ['label' => __('Doctor')]]"
    :description="$many
        ? __('Read-only checks of every domain you manage: DNS, certificates, the HTTPS redirect, PHP and the mail records. They run every night.')
        : __('Read-only checks of the domains of :user: DNS, certificates, the HTTPS redirect, PHP and the mail records. They run every night.', ['user' => $user])">
    <x-slot:actions>
        @if ($canRun && $domains !== [])
            <x-ap-button variant="primary" icon="refresh" :form-action="route('doctor.run', ['user' => $user])" data-doctor-run>{{ __('Run now') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($task !== null)
        <x-ap-task-panel :task="$task" :title="__('Doctor')" :done-message="__('The Doctor has finished.')" :fail-message="__('The Doctor job failed.')" />
    @endif

    <x-ap-card :flush="$domains !== []">
        @if ($domains === [])
            <x-ap-empty icon="globe" :title="__('No domains')">{{ __('Add a web or mail domain; the Doctor checks it every night.') }}</x-ap-empty>
        @else
            @include('doctor.partials.domains', ['domains' => $domains, 'showUser' => $many])
        @endif
    </x-ap-card>
</x-ap-page>
