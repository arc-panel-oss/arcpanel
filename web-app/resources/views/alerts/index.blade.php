{{-- Alerts (E16 spec §7): open alerts, rules, channels and history of one scope, the server's or an account's. Channel secrets are write-only. --}}
@php
    $server = $scope === 'system';
    $title = $server ? __('Server alerts') : __('Alerts');
    $crumbs = $server ? [['label' => __('Server')], ['label' => __('Alerts')]] : [['label' => __('Account')], ['label' => __('Alerts')]];
    $route = fn (string $name, array $params = []) => $server ? route('server.alerts'.$name, $params) : route('alerts'.($name === '' ? '.show' : $name), ['user' => $scope, ...$params]);
    $tones = ['critical' => 'danger', 'warning' => 'warn', 'info' => 'info'];
    $severities = ['info' => __('Info'), 'warning' => __('Warning'), 'critical' => __('Critical')];
    $types = ['email' => __('Email'), 'telegram' => 'Telegram', 'slack' => 'Slack', 'discord' => 'Discord', 'mattermost' => 'Mattermost', 'panel' => __('Panel')];
    $channelName = fn (string $id) => $channels[$id]['NAME'] ?? $id;
    $threshold = function (array $rule, string $value): string {
        if ($value === '') {
            return '';
        }
        $unit = $rule['UNIT'] ?? '';

        return $unit === '%' ? $value.'%' : trim($value.' '.$unit);
    };
    $tabs = ['open' => __('Open').' ('.count($open).')', 'rules' => __('Rules'), 'channels' => __('Channels'), 'history' => __('History')];
    $ruleOptions = ['' => __('Any rule')] + array_map(fn ($rule) => $rule['TITLE'] ?? '', $rules);
    $periods = ['24h' => __('Last 24 hours'), '7d' => __('Last 7 days'), '30d' => __('Last 30 days'), 'all' => __('Any time')];
    $openAdd = $errors->hasAny(['name', 'type', 'target', 'secret']);
@endphp
<x-ap-page :title="$title" :crumbs="$crumbs"
    :description="$server
        ? __('Rules that watch the server, and the channels they report to. A rule sends a message when it fires, a reminder after its cooldown and a message when it is resolved.')
        : __('Rules that watch the domains and backups of :user, and the channels they report to.', ['user' => $scope])">
    <x-ap-tabs :tabs="$tabs" :label="__('Alerts')" :initial="$tab" data-alerts-tabs>
        {{-- Open alerts --}}
        <x-ap-tab-panel name="open">
            <x-ap-card :flush="true">
                @if ($open === [])
                    <x-ap-empty icon="check-circle" :title="__('No open alerts')">{{ __('Every rule that is on finds its values within the thresholds.') }}</x-ap-empty>
                @else
                    <x-ap-table id="alerts-open" :caption="__('Open alerts')" :columns="[
                        ['key' => 'rule', 'label' => __('Rule')], ['key' => 'object', 'label' => __('Object')],
                        ['key' => 'severity', 'label' => __('Severity')], ['key' => 'value', 'label' => __('Value')],
                        ['key' => 'since', 'label' => __('Since')], ['key' => 'count', 'label' => __('Messages'), 'type' => 'number'],
                    ]">
                        @foreach ($open as $alert)
                            <tr data-row data-search="{{ $alert['RULE'] }} {{ $alert['OBJECT'] }}">
                                <td data-col="rule" data-label="{{ __('Rule') }}">{{ $alert['TITLE'] }} <span class="subtle mono">{{ $alert['RULE'] }}</span></td>
                                <td data-col="object" data-label="{{ __('Object') }}" class="mono">{{ $alert['OBJECT'] }}</td>
                                <td data-col="severity" data-label="{{ __('Severity') }}"><x-ap-badge :tone="$tones[$alert['SEVERITY']] ?? null">{{ $severities[$alert['SEVERITY']] ?? $alert['SEVERITY'] }}</x-ap-badge></td>
                                <td data-col="value" data-label="{{ __('Value') }}">{{ $alert['VALUE'] }}@if (($alert['DETAIL'] ?? '') !== '')<span class="subtle"> · {{ $alert['DETAIL'] }}</span>@endif</td>
                                <td data-col="since" data-label="{{ __('Since') }}" class="mono">{{ $alert['FIRED'] }}</td>
                                <td data-col="count" data-label="{{ __('Messages') }}" class="num">{{ $alert['COUNT'] }}</td>
                            </tr>
                        @endforeach
                    </x-ap-table>
                @endif
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- Rules --}}
        <x-ap-tab-panel name="rules">
            <x-ap-card :flush="true">
                <x-ap-table id="alerts-rules" :caption="__('Rules')" :columns="[
                    ['key' => 'rule', 'label' => __('Rule')], ['key' => 'state', 'label' => __('State')],
                    ['key' => 'thresholds', 'label' => __('Thresholds')], ['key' => 'cooldown', 'label' => __('Cooldown')],
                    ['key' => 'channels', 'label' => __('Channels')],
                ]">
                    @foreach ($rules as $name => $rule)
                        @php
                            $event = ($rule['KIND'] ?? '') === 'event';
                            $limits = array_filter([
                                $rule['WARNING'] !== '' ? __('warning').' '.$threshold($rule, $rule['WARNING']) : null,
                                $rule['CRITICAL'] !== '' ? __('critical').' '.$threshold($rule, $rule['CRITICAL']) : null,
                                (int) ($rule['FOR'] ?? 0) > 0 ? __('for :min min', ['min' => $rule['FOR']]) : null,
                                $event ? __('from :severity', ['severity' => $severities[$rule['SEVERITY']] ?? $rule['SEVERITY']]) : null,
                            ]);
                        @endphp
                        <tr data-row data-rule="{{ $name }}" data-search="{{ $name }} {{ $rule['TITLE'] }}">
                            <td data-col="rule" data-label="{{ __('Rule') }}">{{ $rule['TITLE'] }} <span class="subtle mono">{{ $name }}</span></td>
                            <td data-col="state" data-label="{{ __('State') }}">
                                @if ($rule['ENABLED'] === 'yes')
                                    <x-ap-badge tone="ok">{{ __('On') }}</x-ap-badge>
                                @else
                                    <x-ap-badge>{{ __('Off') }}</x-ap-badge>
                                @endif
                            </td>
                            <td data-col="thresholds" data-label="{{ __('Thresholds') }}">{{ implode(', ', $limits) ?: '—' }}</td>
                            <td data-col="cooldown" data-label="{{ __('Cooldown') }}">{{ __(':min min', ['min' => $rule['COOLDOWN']]) }}</td>
                            <td data-col="channels" data-label="{{ __('Channels') }}">
                                {{ implode(', ', array_map($channelName, array_filter(explode(',', $rule['CHANNELS'])))) ?: '—' }}
                            </td>
                            <td class="col-actions">
                                @if ($canManage)
                                    <x-ap-button variant="ghost" size="sm" icon="edit" x-data x-on:click="$dispatch('ap-modal', { id: 'rule-{{ str_replace('.', '-', $name) }}' })">{{ __('Edit') }}<span class="sr-only"> {{ $rule['TITLE'] }}</span></x-ap-button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ap-table>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- Channels --}}
        <x-ap-tab-panel name="channels">
            @if ($canManage)
                <div class="ap-row">
                    <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'add-channel' })">{{ __('Add Channel') }}</x-ap-button>
                </div>
            @endif
            <x-ap-card :flush="true">
                <x-ap-table id="alerts-channels" :caption="__('Channels')" :columns="[
                    ['key' => 'name', 'label' => __('Name')], ['key' => 'type', 'label' => __('Type')],
                    ['key' => 'target', 'label' => __('Sends to')], ['key' => 'status', 'label' => __('Status')],
                    ['key' => 'sent', 'label' => __('Last sent'), 'optional' => true],
                ]">
                    @foreach ($channels as $id => $channel)
                        @php
                            $target = match ($channel['TYPE']) {
                                'panel' => $server ? __('Every administrator') : $scope,
                                'email', 'telegram' => $channel['TARGET'],
                                default => $channel['SECRET_SET'] === 'yes' ? __('Webhook URL saved') : __('No webhook URL'),
                            };
                        @endphp
                        <tr data-row data-channel="{{ $id }}" data-search="{{ $channel['NAME'] }} {{ $channel['TYPE'] }}">
                            <td data-col="name" data-label="{{ __('Name') }}">{{ $channel['NAME'] }}</td>
                            <td data-col="type" data-label="{{ __('Type') }}">{{ $types[$channel['TYPE']] ?? $channel['TYPE'] }}</td>
                            <td data-col="target" data-label="{{ __('Sends to') }}" class="mono">{{ $target }}</td>
                            <td data-col="status" data-label="{{ __('Status') }}">
                                @if ($channel['ENABLED'] !== 'yes')
                                    <x-ap-badge>{{ __('Off') }}</x-ap-badge>
                                @elseif ($channel['STATUS'] === '' || $channel['STATUS'] === 'ok')
                                    <x-ap-badge tone="ok">{{ __('Working') }}</x-ap-badge>
                                @else
                                    <x-ap-badge tone="danger">{{ __('Failing') }}</x-ap-badge>
                                @endif
                                @if ($channel['STATUS'] !== '' && $channel['STATUS'] !== 'ok')
                                    <span class="subtle"> {{ $channel['STATUS'] }}</span>
                                @endif
                            </td>
                            <td data-col="sent" data-label="{{ __('Last sent') }}" class="mono">{{ $channel['LAST_SENT'] }}</td>
                            <td class="col-actions">
                                @if ($canManage)
                                    <div class="ap-row">
                                        <x-ap-button variant="ghost" size="sm" icon="send" :form-action="$route('.channels.test', ['id' => $id])">{{ __('Send test') }}<span class="sr-only"> {{ $channel['NAME'] }}</span></x-ap-button>
                                        <x-ap-button variant="ghost" size="sm" icon="edit" x-data x-on:click="$dispatch('ap-modal', { id: 'channel-{{ $id }}' })">{{ __('Edit') }}<span class="sr-only"> {{ $channel['NAME'] }}</span></x-ap-button>
                                        @if ($channel['TYPE'] !== 'panel')
                                            <x-ap-button variant="ghost" size="sm" icon="trash" :icon-only="true" :label="__('Delete :name', ['name' => $channel['NAME']])"
                                                :form-action="$route('.channels.destroy', ['id' => $id])" form-method="DELETE"
                                                :confirm="__('Delete the channel :name? Rules stop sending to it and its secret is deleted.', ['name' => $channel['NAME']])" :confirm-danger="true" />
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ap-table>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- History --}}
        <x-ap-tab-panel name="history">
            <x-ap-card>
                <form method="GET" action="{{ $route('') }}#history" class="ap-stack" aria-label="{{ __('Filter the history') }}">
                    <input type="hidden" name="tab" value="history">
                    <div class="ap-form-grid">
                        <x-ap-select name="rule" :label="__('Rule')" :options="$ruleOptions" :value="$filters['rule']" />
                        <x-ap-select name="severity" :label="__('Severity')" :options="['' => __('Any severity')] + $severities" :value="$filters['severity']" />
                        <x-ap-select name="period" :label="__('Period')" :options="$periods" :value="$filters['period']" />
                    </div>
                    <div class="ap-form-actions">
                        <x-ap-button type="submit" variant="primary" icon="filter">{{ __('Filter') }}</x-ap-button>
                    </div>
                </form>
            </x-ap-card>
            @if ($historyError !== null)
                <x-ap-alert tone="danger" :title="$historyError" />
            @endif
            <x-ap-card :flush="true">
                @if ($history === [])
                    <x-ap-empty icon="history" :title="__('No alerts')">{{ __('Nothing matches these filters.') }}</x-ap-empty>
                @else
                    <x-ap-table id="alerts-history" :caption="__('History')" :columns="[
                        ['key' => 'time', 'label' => __('Time')], ['key' => 'rule', 'label' => __('Rule')],
                        ['key' => 'object', 'label' => __('Object')], ['key' => 'severity', 'label' => __('Severity')],
                        ['key' => 'state', 'label' => __('State')], ['key' => 'deliveries', 'label' => __('Deliveries'), 'optional' => true],
                    ]">
                        @foreach ($history as $row)
                            @php
                                $deliveries = json_decode((string) $row->channels, true) ?: [];
                                $title = $rules[$row->rule]['TITLE'] ?? $row->rule;
                            @endphp
                            <tr data-row data-search="{{ $row->rule }} {{ $row->object }} {{ $row->message }}">
                                <td data-col="time" data-label="{{ __('Time') }}" class="mono">{{ $row->fired_at }}</td>
                                <td data-col="rule" data-label="{{ __('Rule') }}">{{ $title }}<br><span class="subtle">{{ $row->message }}</span></td>
                                <td data-col="object" data-label="{{ __('Object') }}" class="mono">{{ $row->object }}@if ($row->value)<span class="subtle"> · {{ $row->value }}</span>@endif</td>
                                <td data-col="severity" data-label="{{ __('Severity') }}"><x-ap-badge :tone="$tones[$row->severity] ?? null">{{ $severities[$row->severity] ?? $row->severity }}</x-ap-badge></td>
                                <td data-col="state" data-label="{{ __('State') }}">
                                    @if ($row->state === 'firing')
                                        <x-ap-badge tone="warn">{{ __('Open') }}</x-ap-badge>
                                    @else
                                        <span>{{ __('Resolved') }}</span> <span class="subtle mono">{{ $row->resolved_at }}</span>
                                    @endif
                                </td>
                                <td data-col="deliveries" data-label="{{ __('Deliveries') }}">
                                    @forelse ($deliveries as $delivery)
                                        <div>
                                            <span>{{ $delivery['name'] ?: $delivery['channel'] }}</span>
                                            <x-ap-badge :tone="match ($delivery['result']) { 'sent' => 'ok', 'failed', 'dropped' => 'danger', default => null }">{{ $delivery['result'] }}</x-ap-badge>
                                            @if (($delivery['error'] ?? '') !== '')<span class="subtle">{{ $delivery['error'] }}</span>@endif
                                        </div>
                                    @empty
                                        <span class="subtle">—</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                        <x-slot:footer>
                            <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Alerts\AlertController::PAGE_SIZE"
                                :url="$route('', array_filter(['tab' => 'history', ...$filters]))" />
                        </x-slot:footer>
                    </x-ap-table>
                @endif
            </x-ap-card>
        </x-ap-tab-panel>
    </x-ap-tabs>

    @if ($canManage)
        {{-- One dialog per rule --}}
        @foreach ($rules as $name => $rule)
            @php
                $event = ($rule['KIND'] ?? '') === 'event';
                $levels = $rule['LEVELS'] ?? 'none';
                $selected = array_filter(explode(',', $rule['CHANNELS']));
                $formId = 'rule-form-'.str_replace('.', '-', $name);
            @endphp
            <x-ap-modal :id="'rule-'.str_replace('.', '-', $name)" :title="$rule['TITLE']" :description="$name">
                <x-ap-form :action="$route('.rules.update', ['rule' => $name])" method="PUT" :id="$formId" class="ap-stack">
                    <x-ap-switch name="enabled" :id="$formId.'-enabled'" :label="__('On')" :checked="$rule['ENABLED'] === 'yes'" />
                    @if ($levels !== 'none')
                        <div class="ap-form-grid">
                            <x-ap-input name="warning" :id="$formId.'-warning'" :label="__('Warning at').($rule['UNIT'] !== '' ? ' ('.$rule['UNIT'].')' : '')" :value="$rule['WARNING']" inputmode="decimal" />
                            <x-ap-input name="critical" :id="$formId.'-critical'" :label="__('Critical at').($rule['UNIT'] !== '' ? ' ('.$rule['UNIT'].')' : '')" :value="$rule['CRITICAL']" inputmode="decimal"
                                :hint="$levels === 'below' ? __('Fewer days are worse.') : ($levels === 'warning' ? __('Optional.') : null)" />
                        </div>
                    @endif
                    @if ($event)
                        <x-ap-select name="severity" :id="$formId.'-severity'" :label="__('Send events from')" :options="$severities" :value="$rule['SEVERITY']" />
                    @else
                        <x-ap-input name="for" :id="$formId.'-for'" type="number" min="0" max="1440" :label="__('Minutes the condition must hold')" :value="$rule['FOR']" />
                    @endif
                    <x-ap-input name="cooldown" :id="$formId.'-cooldown'" type="number" min="1" max="43200" :label="__('Minutes between reminders')" :value="$rule['COOLDOWN']" />
                    @if (in_array($name, ['disk.usage', 'inode.usage', 'service.down'], true))
                        <x-ap-input name="options" :id="$formId.'-options'" mono :value="$rule['OPTIONS']"
                            :label="$name === 'service.down' ? __('Services to watch') : __('Mount points to watch')"
                            :hint="__('Separated by commas; empty watches all.')" />
                    @endif
                    <fieldset class="ap-stack">
                        <legend class="ap-label">{{ __('Channels') }}</legend>
                        @foreach ($channels as $id => $channel)
                            <label class="ap-check">
                                <input type="checkbox" name="channels[]" value="{{ $id }}" @checked(in_array($id, $selected, true))>
                                <span>{{ $channel['NAME'] }} <span class="subtle">{{ $types[$channel['TYPE']] ?? $channel['TYPE'] }}</span></span>
                            </label>
                        @endforeach
                    </fieldset>
                </x-ap-form>
                <x-slot:footer>
                    <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                    <x-ap-button type="submit" :form="$formId" variant="primary">{{ __('Save') }}</x-ap-button>
                </x-slot:footer>
            </x-ap-modal>
        @endforeach

        {{-- One dialog per channel --}}
        @foreach ($channels as $id => $channel)
            @php $formId = 'channel-form-'.$id; @endphp
            <x-ap-modal :id="'channel-'.$id" :title="$channel['NAME']" :description="$types[$channel['TYPE']] ?? $channel['TYPE']">
                <x-ap-form :action="$route('.channels.update', ['id' => $id])" method="PUT" :id="$formId" class="ap-stack">
                    <x-ap-input name="name" :id="$formId.'-name'" :label="__('Name')" :value="$channel['NAME']" maxlength="64" required />
                    <x-ap-switch name="enabled" :id="$formId.'-enabled'" :label="__('On')" :checked="$channel['ENABLED'] === 'yes'" />
                    @if ($channel['TYPE'] === 'email')
                        <x-ap-input name="target" :id="$formId.'-target'" :label="__('Addresses')" :value="$channel['TARGET']" :hint="__('One to five, separated by commas.')" />
                    @elseif ($channel['TYPE'] === 'telegram')
                        <x-ap-input name="target" :id="$formId.'-target'" :label="__('Chat id')" :value="$channel['TARGET']" mono />
                    @endif
                    @if (in_array($channel['TYPE'], \App\Services\Alerts\AlertStore::SECRET_TYPES, true))
                        <x-ap-input name="secret" :id="$formId.'-secret'" type="password" autocomplete="off"
                            :label="$channel['TYPE'] === 'telegram' ? __('Bot token') : __('Webhook URL')"
                            :hint="__('Saved. Type a new value only to replace it; it is never shown again.')" />
                    @endif
                </x-ap-form>
                <x-slot:footer>
                    <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                    <x-ap-button type="submit" :form="$formId" variant="primary">{{ __('Save') }}</x-ap-button>
                </x-slot:footer>
            </x-ap-modal>
        @endforeach

        <x-ap-drawer id="add-channel" :title="__('Add Channel')" :description="__('Where the rules of this scope send their messages.')" :open="$openAdd">
            <x-ap-form :action="$route('.channels.store')" id="add-channel-form" class="ap-stack" x-data="{ type: {{ \Illuminate\Support\Js::from((string) old('type', 'telegram')) }} }">
                <x-ap-input name="name" id="channel-name" :label="__('Name')" maxlength="64" required placeholder="Ops chat" />
                <x-ap-select name="type" id="channel-type" :label="__('Type')" x-model="type"
                    :options="['telegram' => 'Telegram', 'slack' => 'Slack', 'discord' => 'Discord', 'mattermost' => 'Mattermost', 'email' => __('Email')]" :value="old('type', 'telegram')" />
                <div x-show="type === 'email'" x-cloak>
                    <x-ap-input name="target" id="channel-addresses" :label="__('Addresses')" :hint="__('One to five, separated by commas.')" x-bind:disabled="type !== 'email'" />
                </div>
                <div x-show="type === 'telegram'">
                    <x-ap-input name="target" id="channel-chat" :label="__('Chat id')" mono
                        :hint="__('The id of the chat or group, or @channelname. Add the bot to the chat first.')" x-bind:disabled="type !== 'telegram'" />
                </div>
                <div x-show="type !== 'email'">
                    <x-ap-input name="secret" id="channel-secret" type="password" autocomplete="off" :label="__('Bot token or webhook URL')"
                        :hint="__('Telegram: the token @BotFather gives. Slack, Discord, Mattermost: the incoming-webhook URL. It is encrypted and never shown again.')"
                        x-bind:disabled="type === 'email'" />
                </div>
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="add-channel-form" variant="primary" icon="lock">{{ __('Save channel') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-drawer>
    @endif
</x-ap-page>
