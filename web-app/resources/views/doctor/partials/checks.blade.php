{{--
    The checks of a Doctor result (E17 spec §5), grouped by status, worst
    first: message, the found value, the docs link, the page that fixes it
    and Fix when the check names an allow-listed command (confirm dialog
    naming the command). $fixUrl gives the fix route of a check id (null
    without the right to run); $links the page of each DR_LINK.
--}}
@php
    $fixUrl ??= null;
    $links ??= [];
    $tones = ['fail' => 'danger', 'warn' => 'warn', 'ok' => 'ok', 'skip' => null];
    $labels = ['fail' => __('Failed'), 'warn' => __('Warnings'), 'ok' => __('Passed'), 'skip' => __('Skipped')];
    $badges = ['fail' => __('Fail'), 'warn' => __('Warning'), 'ok' => __('OK'), 'skip' => __('Skipped')];
    $groups = \App\Services\Doctor\DoctorStore::grouped($result['checks']);
@endphp
@if ($result['checks'] === [])
    <x-ap-card>
        <x-ap-empty icon="check-circle" :title="__('Not checked yet')">{{ __('Run the Doctor to check this now; it also runs every night.') }}</x-ap-empty>
    </x-ap-card>
@else
    @foreach ($groups as $status => $checks)
        <x-ap-card :title="$labels[$status].' ('.count($checks).')'" :flush="true" data-doctor-group="{{ $status }}">
            <ul class="ap-list ap-doctor-checks">
                @foreach ($checks as $check)
                    <li data-doctor-check="{{ $check['id'] }}" data-status="{{ $check['status'] }}">
                        <div class="ap-list-main">
                            <span><x-ap-badge :tone="$tones[$check['status']] ?? null">{{ $badges[$check['status']] ?? $check['status'] }}</x-ap-badge> <strong>{{ $check['title'] }}</strong> <span class="subtle mono">{{ $check['id'] }}</span></span>
                            <span>{{ $check['message'] }}</span>
                            @if ($check['value'] !== '')
                                <span class="subtle mono">{{ $check['value'] }}</span>
                            @endif
                        </div>
                        <div class="ap-row">
                            @if ($check['docs'] !== '')
                                <x-ap-button variant="ghost" size="sm" :href="'https://arcpanel.org'.$check['docs']" target="_blank" rel="noopener">{{ __('Docs') }}<span class="sr-only"> {{ $check['title'] }}</span></x-ap-button>
                            @endif
                            @if ($check['link'] !== '' && ($links[$check['link']] ?? null) !== null && $check['status'] !== 'ok')
                                <x-ap-button variant="ghost" size="sm" :href="$links[$check['link']]">{{ __('Open') }}<span class="sr-only"> {{ $check['title'] }}</span></x-ap-button>
                            @endif
                            @if ($check['fix'] !== null && $fixUrl !== null && in_array($check['status'], ['fail', 'warn'], true))
                                <x-ap-button variant="primary" size="sm" icon="check" :form-action="$fixUrl($check['id'])"
                                    :confirm="sprintf(__('Run %s? The check runs again afterwards.'), $check['fix']['command'])"
                                    :confirm-title="$check['fix']['label']" :confirm-accept="__('Fix')" data-doctor-fix="{{ $check['id'] }}">{{ __('Fix') }}<span class="sr-only"> {{ $check['title'] }}</span></x-ap-button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ap-card>
    @endforeach
@endif
