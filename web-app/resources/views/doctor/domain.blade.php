{{-- The Doctor checks of one domain (E17 spec §5): its web and mail checks, Run now, Fix. --}}
@php
    $links = [
        'dns' => route('dns.records.index', ['user' => $user, 'domain' => $domain]),
        'php' => route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#php',
    ];
    $fixUrl = $canRun ? fn (string $check) => route('doctor.domain.fix', ['user' => $user, 'domain' => $domain, 'check' => $check]) : null;
    $scoreTone = fn (?int $score) => $score === null ? null : ($score >= 90 ? 'ok' : ($score >= 70 ? 'warn' : 'danger'));
    $counts = array_count_values(array_column($result['checks'], 'status'));
@endphp
<x-ap-page :title="$domain" :crumbs="[['label' => __('Doctor'), 'url' => route('doctor.show', ['user' => $user])], ['label' => $domain]]"
    :description="__('Read-only checks of the web and mail parts of :domain. They run every night.', ['domain' => $domain])">
    <x-slot:actions>
        @if ($canRun)
            <x-ap-button variant="primary" icon="refresh" :form-action="route('doctor.domain.run', ['user' => $user, 'domain' => $domain])" data-doctor-run>{{ __('Run now') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($task !== null)
        <x-ap-task-panel :task="$task" :title="__('Doctor')" :done-message="__('The Doctor has finished.')" :fail-message="__('The Doctor job failed.')" />
    @endif

    <x-ap-card>
        <div class="ap-row" data-doctor-score="{{ $result['score'] ?? '' }}">
            <x-ap-badge :tone="$scoreTone($result['score'])">{{ $result['score'] !== null ? __('Score :score', ['score' => $result['score']]) : __('No score') }}</x-ap-badge>
            <span>{{ trans_choice(':fail failed, :warn warning, :ok passed, :skip skipped|:fail failed, :warn warnings, :ok passed, :skip skipped', $counts['warn'] ?? 0, ['fail' => $counts['fail'] ?? 0, 'warn' => $counts['warn'] ?? 0, 'ok' => $counts['ok'] ?? 0, 'skip' => $counts['skip'] ?? 0]) }}</span>
            <span class="muted">{{ $result['checked_at'] !== null ? __('Checked :time', ['time' => $result['checked_at']]) : __('Never checked') }}</span>
        </div>
    </x-ap-card>

    @include('doctor.partials.checks', ['result' => $result, 'fixUrl' => $fixUrl, 'links' => $links])
</x-ap-page>
