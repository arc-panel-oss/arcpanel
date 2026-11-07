{{-- The domains of a Doctor summary (E17 spec §5): score, failed and warning counts, the failing checks; worst first. --}}
@php
    $scoreTone = fn (?int $score) => $score === null ? null : ($score >= 90 ? 'ok' : ($score >= 70 ? 'warn' : 'danger'));
    $columns = array_values(array_filter([
        ['key' => 'domain', 'label' => __('Domain'), 'sortable' => true],
        $showUser ? ['key' => 'user', 'label' => __('User'), 'sortable' => true] : null,
        ['key' => 'score', 'label' => __('Score'), 'type' => 'number', 'sortable' => true],
        ['key' => 'failing', 'label' => __('Failing checks')],
        ['key' => 'checked', 'label' => __('Checked')],
    ]));
@endphp
<x-ap-table id="doctor-domains" :caption="__('Domains')" :columns="$columns" :search-label="__('Search domains')">
    @foreach ($domains as $row)
        <tr data-row data-search="{{ $row['domain'] }} {{ $row['user'] }}" data-doctor-domain="{{ $row['domain'] }}">
            <td data-col="domain" data-label="{{ __('Domain') }}"><a class="mono" href="{{ route('doctor.domain', ['user' => $row['user'], 'domain' => $row['domain']]) }}">{{ $row['domain'] }}</a></td>
            @if ($showUser)
                <td data-col="user" data-label="{{ __('User') }}" class="mono">{{ $row['user'] }}</td>
            @endif
            <td data-col="score" data-label="{{ __('Score') }}" class="num" data-sort="{{ $row['score'] ?? 101 }}">
                <x-ap-badge :tone="$scoreTone($row['score'])">{{ $row['score'] ?? '—' }}</x-ap-badge>
            </td>
            <td data-col="failing" data-label="{{ __('Failing checks') }}">
                @if ($row['fail'] + $row['warn'] === 0)
                    <span class="muted">{{ $row['checked_at'] !== null ? __('None') : '—' }}</span>
                @else
                    {{ \App\Services\Doctor\DoctorStore::failingSummary($row['fail'], $row['warn']) }}
                    @if ($row['failing'] !== [])
                        <span class="subtle mono">{{ implode(', ', $row['failing']) }}</span>
                    @endif
                @endif
            </td>
            <td data-col="checked" data-label="{{ __('Checked') }}" class="mono">{{ $row['checked_at'] ?? __('Never') }}</td>
        </tr>
    @endforeach
</x-ap-table>
