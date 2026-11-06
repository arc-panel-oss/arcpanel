{{-- Server › Doctor (E17 spec §5): the server score and checks, Run now, and every domain with its score, worst first. --}}
@php
    $links = [
        'updates' => route('server.updates'),
        'panel-ssl' => route('server.settings.tab', ['tab' => 'ssl']),
        'ports' => route('server.ports'),
    ];
    $fixUrl = $canRun ? fn (string $check) => route('server.doctor.fix', ['check' => $check]) : null;
    $scoreTone = fn (?int $score) => $score === null ? null : ($score >= 90 ? 'ok' : ($score >= 70 ? 'warn' : 'danger'));
    $counts = array_count_values(array_column($result['checks'], 'status'));
@endphp
<x-ap-page :title="__('Server Doctor')" :crumbs="[['label' => __('Server')], ['label' => __('Doctor')]]"
    :description="__('Read-only checks of the server and of every domain. They run every night; a check that starts to fail sends an alert.')">
    <x-slot:actions>
        @if ($canRun)
            <x-ap-button variant="primary" icon="refresh" :form-action="route('server.doctor.run')" data-doctor-run>{{ __('Run now') }}</x-ap-button>
            <x-ap-button icon="refresh" :form-action="route('server.doctor.run', ['all' => 1])">{{ __('Check everything') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($task !== null)
        <x-ap-task-panel :task="$task" :title="__('Doctor')" :done-message="__('The Doctor has finished.')" :fail-message="__('The Doctor job failed.')" />
    @endif

    <x-ap-card :title="__('Server')" icon="server">
        <div class="ap-row" data-doctor-score="{{ $result['score'] ?? '' }}">
            <x-ap-badge :tone="$scoreTone($result['score'])">{{ $result['score'] !== null ? __('Score :score', ['score' => $result['score']]) : __('No score') }}</x-ap-badge>
            <span>{{ __(':fail failed, :warn warnings, :ok passed, :skip skipped', ['fail' => $counts['fail'] ?? 0, 'warn' => $counts['warn'] ?? 0, 'ok' => $counts['ok'] ?? 0, 'skip' => $counts['skip'] ?? 0]) }}</span>
            <span class="muted">{{ $result['checked_at'] !== null ? __('Checked :time', ['time' => $result['checked_at']]) : __('Never checked') }}</span>
        </div>
    </x-ap-card>

    @include('doctor.partials.checks', ['result' => $result, 'fixUrl' => $fixUrl, 'links' => $links])

    <x-ap-card :title="__('Domains')" icon="globe" :flush="$domains !== []">
        @if ($domains === [])
            <x-ap-empty icon="globe" :title="__('No domains')">{{ __('No user has a web or mail domain yet.') }}</x-ap-empty>
        @else
            @include('doctor.partials.domains', ['domains' => $domains, 'showUser' => true])
        @endif
    </x-ap-card>
</x-ap-page>
