{{-- Server › Audit log (E23 spec §7.1): filters, 50 events a page, a detail drawer, export and the chain check. --}}
@php
    $columns = [
        ['key' => 'time', 'label' => __('Time'), 'sortable' => false],
        ['key' => 'actor', 'label' => __('Actor'), 'sortable' => false],
        ['key' => 'action', 'label' => __('Action'), 'sortable' => false],
        ['key' => 'object', 'label' => __('Object'), 'sortable' => false],
        ['key' => 'result', 'label' => __('Result'), 'sortable' => false],
        ['key' => 'ip', 'label' => __('IP address'), 'sortable' => false, 'optional' => true],
        ['key' => 'details', 'label' => __('Details'), 'sortable' => false],
    ];
    $tones = ['ok' => 'ok', 'denied' => 'warn', 'invalid' => 'info', 'failed' => 'danger'];
    $results = ['' => __('Any result'), 'ok' => __('OK'), 'denied' => __('Denied'), 'invalid' => __('Invalid'), 'failed' => __('Failed')];
    $channels = ['' => __('Any channel'), 'web' => __('Panel'), 'api_v2' => __('REST API v2'), 'api_v1' => __('REST API v1'), 'system' => __('System')];
    $link = fn (string $route, array $extra = []) => route($route, array_merge($query, $extra));
@endphp
<x-ap-page :title="__('Audit log')" :crumbs="[['label' => $canVerify ? __('Server') : __('Accounts')], ['label' => __('Audit log')]]"
    :description="$canVerify
        ? __('Who changed what on this server, from the panel and the APIs. The log is chained with SHA-256; root on the server can still rewrite it, so forward it to syslog for an off-server copy.')
        : __('Who changed what in your accounts and those of your customers.')">
    <x-slot:actions>
        @if ($canVerify)
            <x-ap-button :href="$link('audit.verify')" icon="shield-check">{{ __('Verify chain') }}</x-ap-button>
        @endif
        <x-ap-button :href="$link('audit.export', ['format' => 'csv'])" icon="download">{{ __('Export CSV') }}</x-ap-button>
        <x-ap-button :href="$link('audit.export', ['format' => 'json'])" icon="download">{{ __('Export JSON') }}</x-ap-button>
    </x-slot:actions>

    @if ($verification !== null)
        @if ($verification['ok'])
            <x-ap-alert tone="ok" :title="__('The chain is intact')" data-audit-verify="ok">
                {{ sprintf(__('%1$d events from %2$s to %3$s verified.'), $verification['count'], $verification['from'], $verification['to']) }}
            </x-ap-alert>
        @else
            <x-ap-alert tone="danger" :title="__('The chain is broken')" data-audit-verify="broken">
                {{ sprintf(__('%1$s, line %2$d: %3$s'), $verification['day'], $verification['line'], $verification['reason']) }}
            </x-ap-alert>
        @endif
    @endif

    <x-ap-card>
        <form method="GET" action="{{ route('audit.index') }}" class="ap-stack" aria-label="{{ __('Filter the audit log') }}">
            <div class="ap-form-grid">
                <x-ap-input name="from" type="date" :label="__('From')" :value="$filters['from']" />
                <x-ap-input name="to" type="date" :label="__('To')" :value="$filters['to']" />
                <x-ap-input name="actor" :label="__('Actor')" :value="$filters['actor']" mono :hint="__('The account that signed in or owns the access key.')" />
                <x-ap-input name="user" :label="__('User')" :value="$filters['user']" mono :hint="__('The actor, the account acted on or the owner of the object.')" />
                <x-ap-input name="action" :label="__('Action')" :value="$filters['action']" mono :hint="__('The start of the action, for example web.domains or auth.')" />
                <x-ap-select name="result" :label="__('Result')" :options="$results" :value="$filters['result'] ?? ''" />
                <x-ap-select name="channel" :label="__('Channel')" :options="$channels" :value="$filters['channel'] ?? ''" />
            </div>
            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="filter">{{ __('Filter') }}</x-ap-button>
                <x-ap-button :href="route('audit.index')" variant="ghost">{{ __('Reset') }}</x-ap-button>
            </div>
        </form>
    </x-ap-card>

    <x-ap-card :flush="true" x-data="{ detail: '' }">
        @if ($events === [])
            <x-ap-empty icon="history" :title="__('No events')">{{ __('Nothing matches these filters in this range.') }}</x-ap-empty>
        @else
            <x-ap-table id="audit-log" :columns="$columns" :search-label="__('Search events')" :caption="__('Audit log')">
                @foreach ($events as $event)
                    @php
                        $actor = $event['actor']['user'] ?? '';
                        $effective = $event['effective_user'] ?? null;
                        $object = $event['object'] ?? null;
                        $objectText = $object === null ? '' : trim(($object['type'] ?? '').' '.($object['name'] ?? ''));
                        $result = (string) ($event['result'] ?? '');
                    @endphp
                    <tr data-row data-search="{{ $actor }} {{ $effective }} {{ $event['action'] ?? '' }} {{ $objectText }}" data-audit-id="{{ $event['id'] ?? '' }}">
                        <td data-col="time" data-label="{{ __('Time') }}" class="ap-mono">{{ str_replace(['T', 'Z'], [' ', ''], (string) ($event['ts'] ?? '')) }}</td>
                        <td data-col="actor" data-label="{{ __('Actor') }}">
                            {{ $actor !== '' ? $actor : __('Unknown') }}
                            @if ($effective !== null && $effective !== $actor)
                                <span class="muted">{{ sprintf(__('as %s'), $effective) }}</span>
                            @endif
                            @if (($event['actor']['key'] ?? null) !== null)
                                <span class="muted ap-mono">{{ $event['actor']['key'] }}</span>
                            @endif
                        </td>
                        <td data-col="action" data-label="{{ __('Action') }}" class="ap-mono">{{ $event['action'] ?? '' }}</td>
                        <td data-col="object" data-label="{{ __('Object') }}">{{ $objectText }}</td>
                        <td data-col="result" data-label="{{ __('Result') }}"><x-ap-badge :tone="$tones[$result] ?? null">{{ $results[$result] ?? $result }}</x-ap-badge></td>
                        <td data-col="ip" data-label="{{ __('IP address') }}" class="ap-mono">{{ $event['actor']['ip'] ?? '' }}</td>
                        <td data-col="details" data-label="{{ __('Details') }}">
                            <x-ap-button variant="ghost" size="sm" icon="eye" :label="__('Show the event')"
                                x-on:click="detail = {{ \Illuminate\Support\Js::from(json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) }}; $dispatch('ap-modal', { id: 'audit-event' })">{{ __('Show') }}</x-ap-button>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Audit\AuditController::PAGE_SIZE"
                        :url="route('audit.index', $query)" />
                </x-slot:footer>
            </x-ap-table>
        @endif

        <x-ap-drawer id="audit-event" :title="__('Audit event')" :description="__('The event as the log holds it, with its place in the hash chain.')">
            <pre class="ap-code" x-text="detail" tabindex="0"></pre>
        </x-ap-drawer>
    </x-ap-card>
</x-ap-page>
