{{-- The Updates page of the Update Center (E33 phase 1 spec §8, templates/nocturne/admin/updates*.html). --}}
@php
    $page = new \App\View\UpdateCenterPage($policy);
    $days = \App\View\UpdateCenterPage::dayNames();
    [$windowFrom, $windowTo] = $page->windowHours();
    $paused = $policy['paused'] !== null;
    $manifestOk = $releases !== null && $policy['manifestError'] === null;
    $taskRunning = ($task['status'] ?? '') === 'running';
    $newest = $releases[0] ?? null;
    $preflightLabels = [
        'manifest' => __('Manifest'),
        'version' => __('Version'),
        'disk' => __('Disk'),
        'packages' => __('Package manager'),
        'activity' => __('Activity'),
        'attention' => __('Attention'),
    ];
    $kindTones = ['patch' => null, 'minor' => 'accent', 'major' => 'accent'];
@endphp
<x-ap-page :title="__('Updates')" :eyebrow="__('Server · update center')" :crumbs="[['label' => __('Server')], ['label' => __('Updates')]]"
    :description="sprintf(__('ArcPanel packages from the %s channel. Operating system packages are not managed here.'), $policy['channel'] !== '' ? $policy['channel'] : 'stable')">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button icon="refresh" :form-action="route('server.updates.check')">{{ __('Check now') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($paused)
        <x-ap-alert tone="danger" :title="__('Automatic updates are paused')">
            {{ $policy['paused'] }}
            <x-slot:actions>
                @if ($canManage)
                    <x-ap-button variant="danger" size="sm" icon="play" :form-action="route('server.updates.resume')">{{ __('Resume') }}</x-ap-button>
                @endif
            </x-slot:actions>
        </x-ap-alert>
    @endif

    @if (! $manifestOk)
        <x-ap-alert tone="warn" :title="__('The release manifest could not be verified')">
            {{ $manifestError ?? $policy['manifestError'] }}
            {{ $releases !== null ? __('The list below is the last verified copy. Automatic updates wait until a manifest verifies.') : __('Automatic updates wait until a manifest verifies.') }}
            <x-slot:actions>
                @if ($canManage)
                    <x-ap-button size="sm" :form-action="route('server.updates.check')">{{ __('Try again') }}</x-ap-button>
                    <x-ap-button size="sm" variant="ghost" x-data x-on:click="$dispatch('ap-modal', { id: 'update-latest' })">{{ __('Update to latest…') }}</x-ap-button>
                @endif
            </x-slot:actions>
        </x-ap-alert>
    @endif

    @if (! $canManage)
        <x-ap-alert tone="info" :title="__('Read-only session')">{{ __('You see the releases, the policy and the history; buttons and the policy form are disabled.') }}</x-ap-alert>
    @endif

    <section class="ap-status-strip" aria-label="{{ __('Update status') }}">
        <div class="ap-status-cell">
            <span class="ap-status-label">{{ __('Installed') }}</span>
            <span class="ap-status-value mono">{{ $policy['version'] }}</span>
            <span class="ap-status-meta">{{ sprintf(__('Line %s'), $policy['line']) }}</span>
        </div>
        <div class="ap-status-cell">
            <span class="ap-status-label">{{ __('Policy') }}</span>
            <span class="ap-status-value"><span @class(['ap-status-dot', 'is-ok' => $policy['policy'] === 'patch' && ! $paused, 'is-warn' => $paused]) aria-hidden="true"></span>{{ \App\View\UpdateCenterPage::policyLabel($policy['policy']) }}</span>
            <span class="ap-status-meta">{{ $policy['policy'] === 'off' ? __('Nothing is checked.') : $page->windowSummary() }}</span>
        </div>
        <div class="ap-status-cell">
            <span class="ap-status-label">{{ __('Next window') }}</span>
            <span class="ap-status-value">{{ $policy['nextWindow'] !== null ? \App\View\UpdateCenterPage::time($policy['nextWindow']) : '–' }}</span>
            <span class="ap-status-meta">{{ $policy['policy'] === 'patch' ? ($paused ? __('Paused') : __('Patch releases install in the window.')) : __('Only with the policy Patch releases.') }}</span>
        </div>
        <div class="ap-status-cell">
            <span class="ap-status-label">{{ __('Release manifest') }}</span>
            <span class="ap-status-value"><x-ap-icon :name="$manifestOk ? 'shield-check' : 'alert'" @class(['is-ok' => $manifestOk, 'is-warn' => ! $manifestOk]) />{{ $manifestOk ? __('Verified') : __('Not verified') }}</span>
            <span class="ap-status-meta">{{ $policy['manifestChecked'] !== null ? sprintf(__('Checked %s'), \App\View\UpdateCenterPage::time($policy['manifestChecked'])) : __('Not checked yet') }}</span>
        </div>
    </section>

    <x-ap-task-panel id="update-task" :task="$task" :title="__('Updating')"
        :done-message="__('The update has finished.')" :fail-message="__('The update failed.')">
        <div class="ap-updates-grid" x-data="apUpdateRelease({ url: @js(route('server.updates.preflight', ['version' => 'VERSION'])) })">
            <x-ap-card :title="__('Available releases')" icon="download" :flush="true">
                @if ($releases === null)
                    <x-ap-empty icon="alert" :title="__('No verified release manifest')">{{ __('The releases cannot be listed until the manifest verifies.') }}</x-ap-empty>
                @elseif ($releases === [])
                    <x-ap-empty icon="shield-check" :title="__('ArcPanel is up to date')">{{ sprintf(__('%s is the newest release on this channel.'), $policy['version']) }}</x-ap-empty>
                @else
                    <ul class="ap-releases">
                        @foreach ($releases as $release)
                            @php
                                $version = (string) ($release['VERSION'] ?? '');
                                $kind = (string) ($release['KIND'] ?? '');
                                $withdrawn = is_array($release['WITHDRAWN'] ?? null);
                                $attention = ($release['ATTENTION'] ?? false) === true;
                                $items = array_values(array_map('strval', is_array($release['ATTENTION_ITEMS'] ?? null) ? $release['ATTENTION_ITEMS'] : []));
                                $notes = is_string($release['NOTES'] ?? null) && $release['NOTES'] !== '' ? $release['NOTES'] : null;
                                $line = $page->reasonLine($release);
                            @endphp
                            <li @class(['ap-release', 'is-withdrawn' => $withdrawn]) data-release="{{ $version }}">
                                <div class="ap-release-main">
                                    <div class="ap-release-head">
                                        <span class="ap-release-version">{{ $version }}</span>
                                        <x-ap-badge :tone="$kindTones[$kind] ?? null" :dot="false" @class(['ap-badge-strong' => $kind === 'major'])>{{ $kind }}</x-ap-badge>
                                        @if (($release['SECURITY'] ?? false) === true)<x-ap-badge tone="danger" :dot="false">{{ __('security') }}</x-ap-badge>@endif
                                        @if ($attention)<x-ap-badge tone="warn" :dot="false">{{ __('attention') }}</x-ap-badge>@endif
                                        @if ($withdrawn)<x-ap-badge tone="danger" :dot="false">{{ __('withdrawn') }}</x-ap-badge>@endif
                                        <span class="subtle">{{ \App\View\UpdateCenterPage::date((string) ($release['DATE'] ?? '')) }}</span>
                                        @if ($notes !== null)
                                            <a href="{{ $notes }}" target="_blank" rel="noopener">{{ __('Release notes') }}<x-ap-icon name="external" size="sm" /></a>
                                        @endif
                                    </div>
                                    <p @class(['ap-release-reason', 'is-'.$line['tone'] => $line['tone'] !== null])><x-ap-icon :name="$line['icon']" /><span>{{ $line['text'] }}</span></p>
                                    @if ($attention && $items !== [] && ! $withdrawn)
                                        <ul class="ap-release-items">
                                            @foreach ($items as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                                <div class="ap-release-action">
                                    @if ($withdrawn)
                                        <span class="subtle">{{ __('Not installable') }}</span>
                                    @elseif ($canManage)
                                        <x-ap-button :variant="$attention ? null : 'primary'" :icon="$attention ? null : 'download'" x-bind:disabled="running"
                                            x-on:click="review({{ \Illuminate\Support\Js::from(['version' => $version, 'kind' => $kind, 'attention' => $attention, 'items' => $attention ? $items : []]) }})">
                                            {{ $attention ? __('Review and update') : sprintf(__('Update to %s'), $version) }}
                                        </x-ap-button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>

            <div class="ap-stack">
                <x-ap-card :title="__('Automatic updates')" icon="refresh">
                    <x-ap-form :action="route('server.updates.policy')">
                        <fieldset class="ap-fieldset-plain" @disabled(! $canManage)>
                            <fieldset class="ap-fieldset">
                                <legend class="ap-label">{{ __('Policy') }}</legend>
                                <div class="ap-picker ap-picker-stack">
                                    @foreach (['off' => __('Never check for new versions.'), 'notify' => __('Check every hour and announce new versions. Install nothing.'), 'patch' => sprintf(__('Also install patch releases of %s that need no attention. Recommended.'), $policy['line'])] as $value => $hint)
                                        <label class="ap-pick">
                                            <input type="radio" name="policy" value="{{ $value }}" @checked(old('policy', $policy['policy']) === $value)>
                                            <b>{{ \App\View\UpdateCenterPage::policyLabel($value) }}</b><span>{{ $hint }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <fieldset class="ap-fieldset">
                                <legend class="ap-label">{{ __('Days') }}</legend>
                                <div class="ap-days">
                                    @foreach ($days as $day => $dayName)
                                        <label class="ap-day"><input type="checkbox" name="window_days[]" value="{{ $day }}" @checked(in_array($day, old('window_days', $policy['windowDays']), true))><span>{{ $dayName }}</span></label>
                                    @endforeach
                                </div>
                                @error('window_days')<p class="ap-field-error">{{ $message }}</p>@enderror
                            </fieldset>
                            <div class="ap-window-grid">
                                <x-ap-select name="window_from" :label="__('From')" :value="(string) $windowFrom"
                                    :options="collect(range(0, 23))->mapWithKeys(fn ($h) => [(string) $h => sprintf('%02d:00', $h)])->all()" />
                                <x-ap-select name="window_to" :label="__('Until')" :value="(string) $windowTo"
                                    :options="collect(range(1, 24))->mapWithKeys(fn ($h) => [(string) $h => sprintf('%02d:00', $h)])->all()" />
                                <x-ap-input name="delay_days" type="number" min="0" max="30" :label="__('Delay, days')" :value="(string) $policy['delayDays']" />
                            </div>
                            <p class="ap-hint">{{ __('Server time. The window may run past midnight. Security releases skip the delay and install in the next window.') }}</p>
                            @if ($canManage)
                                <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save policy') }}</x-ap-button></div>
                            @endif
                        </fieldset>
                    </x-ap-form>
                </x-ap-card>

                <x-ap-card :title="__('Installed packages')" icon="package" :flush="true">
                    @if ($packages === [])
                        <x-ap-empty icon="package" :title="__('No packages')" />
                    @else
                        <dl class="ap-pkg-list">
                            @foreach ($packages as $package)
                                <div><dt>{{ $package['name'] }}</dt><dd>{{ $package['version'] }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </x-ap-card>

                <x-ap-card :title="__('Notifications')" icon="mail">
                    <x-ap-form :action="route('server.updates.notifications')">
                        <fieldset class="ap-fieldset-plain" @disabled(! $canManage)>
                            <x-ap-switch name="send_email" :label="__('Send email notification when an update has been installed')" :checked="$sendEmail" />
                            <x-ap-switch name="send_log" :label="__('Send update installation log by email')" :checked="$sendLog" />
                        </fieldset>
                        @if ($canManage)
                            <div class="ap-form-actions"><x-ap-button type="submit" icon="check">{{ __('Save') }}</x-ap-button></div>
                        @endif
                    </x-ap-form>
                </x-ap-card>
            </div>

            {{-- The update dialog: pre-flight, attention items, then the update task --}}
            <x-ap-modal id="update-release" :title="__('Update ArcPanel')" class="is-wide">
                <template x-if="release">
                    <div class="ap-stack">
                        <p class="ap-row">
                            <span>{{ sprintf(__('From %s to'), $policy['version']) }} <b class="mono" x-text="release.version"></b></span>
                            <span class="ap-badge" x-bind:class="release.kind !== 'patch' && 'is-accent'" x-text="release.kind"></span>
                            <span class="ap-badge is-warn" x-show="release.attention">{{ __('attention') }}</span>
                        </p>
                        <section class="ap-field" aria-labelledby="update-release-checks">
                            <h3 class="ap-label" id="update-release-checks">{{ __('Pre-flight checks') }}</h3>
                            <p class="ap-row muted" x-show="loading"><span class="ap-spinner" aria-hidden="true"></span>{{ __('Checking…') }}</p>
                            <p class="ap-field-error" x-show="error" x-text="error"></p>
                            <ul class="ap-preflight" x-show="checks.length > 0">
                                <template x-for="check in checks" x-bind:key="check.check">
                                    <li x-bind:class="'is-' + check.status">
                                        <x-ap-icon name="check-circle" x-show="check.status === 'ok'" />
                                        <x-ap-icon name="alert" x-show="check.status === 'warn'" />
                                        <x-ap-icon name="x-circle" x-show="check.status === 'fail'" />
                                        <span><b x-text="{{ \Illuminate\Support\Js::from($preflightLabels) }}[check.check] ?? check.check"></b><span x-text="check.message"></span></span>
                                    </li>
                                </template>
                            </ul>
                        </section>
                        <fieldset class="ap-confirm-items" x-show="release.items.length > 0">
                            <legend>{{ __('I have read what changes') }}</legend>
                            <template x-for="(item, index) in release.items" x-bind:key="index">
                                <label><input type="checkbox" x-model="confirmed[index]"><span x-text="item"></span></label>
                            </template>
                        </fieldset>
                        <x-ap-alert tone="info">{{ __('The update saves the configuration, installs the packages and runs a health check. Services restart for a few seconds. A patch release without an upgrade step rolls back on its own when the health check fails; any other release pauses automatic updates and you get an e-mail.') }}</x-ap-alert>
                    </div>
                </template>
                <x-slot:footer>
                    <span class="ap-modal-foot-note" x-show="release && release.items.length > 0"
                        x-text="{{ \Illuminate\Support\Js::from(__('%1$s of %2$s confirmed')) }}.replace('%1$s', confirmedCount).replace('%2$s', confirmed.length)"></span>
                    <x-ap-button variant="ghost" x-on:click="$dispatch('ap-modal', { id: 'update-release', open: false })">{{ __('Cancel') }}</x-ap-button>
                    <form method="post" action="{{ route('server.updates.release') }}" class="ap-inline-form">
                        @csrf
                        <input type="hidden" name="version" x-bind:value="release ? release.version : ''">
                        <input type="hidden" name="confirm_attention" value="1" x-bind:disabled="!(release && release.attention)">
                        <x-ap-button type="submit" variant="primary" icon="download" x-bind:disabled="!canUpdate">{{ __('Update') }}</x-ap-button>
                    </form>
                </x-slot:footer>
            </x-ap-modal>
        </div>

        <x-ap-card :title="__('History')" icon="history" :flush="true">
            @if ($history === [])
                <x-ap-empty icon="history" :title="__('No updates yet')">{{ __('Updates from the policy, this page and the API are listed here.') }}</x-ap-empty>
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table ap-table-cards">
                        <caption class="sr-only">{{ __('History') }}</caption>
                        <thead><tr>
                            <th scope="col">{{ __('Run') }}</th>
                            <th scope="col">{{ __('Change') }}</th>
                            <th scope="col">{{ __('Result') }}</th>
                            <th scope="col">{{ __('Trigger') }}</th>
                            <th scope="col">{{ __('Started') }}</th>
                            <th scope="col">{{ __('Took') }}</th>
                            <th scope="col" class="col-actions"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($history as $run)
                                @php
                                    $result = \App\View\UpdateCenterPage::result((string) ($run['RESULT'] ?? ''));
                                    $reason = is_string($run['REASON'] ?? null) && $run['REASON'] !== '' ? $run['REASON'] : null;
                                    $actor = is_string($run['ACTOR'] ?? null) && $run['ACTOR'] !== '' ? $run['ACTOR'] : null;
                                    $took = \App\View\UpdateCenterPage::took($run['START'] ?? null, $run['END'] ?? null);
                                    $canRollBack = ($run['ROLLBACK'] ?? '') === 'yes';
                                    $rollbackReason = is_string($run['ROLLBACK_REASON'] ?? null) && $run['ROLLBACK_REASON'] !== '' ? $run['ROLLBACK_REASON'] : null;
                                    $isLastTask = ($run['TASK'] ?? '') !== '' && ($run['TASK'] ?? '') === ($task['id'] ?? '');
                                @endphp
                                <tr data-run="{{ $run['ID'] ?? '' }}">
                                    <td data-label="{{ __('Run') }}"><span class="mono subtle">#{{ $run['ID'] ?? '' }}</span></td>
                                    <td data-label="{{ __('Change') }}"><span class="mono">{{ $run['FROM'] ?? '' }} <span class="subtle">→</span> {{ $run['TO'] ?? '' }}</span></td>
                                    <td data-label="{{ __('Result') }}">
                                        <div class="ap-cell-main">
                                            <x-ap-badge :tone="$result['tone']">{{ $result['label'] }}</x-ap-badge>
                                            @if ($reason !== null)<span>{{ $reason }}</span>@endif
                                        </div>
                                    </td>
                                    <td data-label="{{ __('Trigger') }}"><span class="muted">{{ \App\View\UpdateCenterPage::trigger((string) ($run['TRIGGER'] ?? '')) }}@if ($actor !== null) · @endif</span>@if ($actor !== null)<span class="mono">{{ $actor }}</span>@endif</td>
                                    <td data-label="{{ __('Started') }}"><span class="muted">{{ is_string($run['START'] ?? null) && $run['START'] !== '' ? \App\View\UpdateCenterPage::time($run['START']) : '–' }}</span></td>
                                    <td data-label="{{ __('Took') }}"><span class="muted">{{ $took ?? '–' }}</span></td>
                                    <td class="col-actions">
                                        <div class="ap-actions">
                                            @if ($isLastTask)
                                                <a href="#update-task">{{ __('Task log') }}</a>
                                            @endif
                                            @if ($canRollBack && $canManage)
                                                <x-ap-button size="sm" icon="return" x-on:click="$dispatch('ap-modal', { id: 'rollback-run' })">{{ __('Roll back') }}</x-ap-button>
                                            @elseif ($loop->first && $rollbackReason !== null)
                                                <span class="subtle" title="{{ $rollbackReason }}">{{ __('No rollback') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-task-panel>

    @php($rollbackRun = collect($history)->first(fn ($run) => ($run['ROLLBACK'] ?? '') === 'yes'))
    @if ($rollbackRun !== null && $canManage)
        <x-ap-modal id="rollback-run" icon="return" :title="sprintf(__('Roll back to %s?'), $rollbackRun['FROM'] ?? '')"
            :description="sprintf(__('Run #%1$s updated this server from %2$s to %3$s. Rolling back reinstalls %2$s and its engine packages, restores the configuration saved before that run and restarts the panel.'), $rollbackRun['ID'] ?? '', $rollbackRun['FROM'] ?? '', $rollbackRun['TO'] ?? '')">
            <x-ap-alert tone="warn">{{ __('Panel settings changed since that run return to their earlier values. Users, sites and mail data are not touched.') }}</x-ap-alert>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button variant="warn" icon="return" :form-action="route('server.updates.rollback', ['id' => (int) ($rollbackRun['ID'] ?? 0)])">{{ sprintf(__('Roll back to %s'), $rollbackRun['FROM'] ?? '') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-modal>
    @endif

    @if (! $manifestOk && $canManage)
        {{-- The box and the button share one form, so they share its x-data --}}
        <x-ap-modal id="update-latest" icon="alert" :title="__('Update to the newest packages?')"
            :description="__('Without a verified manifest the panel cannot check the release: no pre-flight against the manifest, no attention items and no rollback. APT installs the newest ArcPanel packages it finds, even on a newer line.')">
            <form method="post" action="{{ route('server.updates.update') }}" class="ap-stack" x-data="{ understood: false }">
                @csrf
                <fieldset class="ap-confirm-items">
                    <legend>{{ __('Confirm') }}</legend>
                    <label><input type="checkbox" x-model="understood"><span>{{ __('I understand that this may move the server to a new line.') }}</span></label>
                </fieldset>
                <div class="ap-form-actions">
                    <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                    <x-ap-button type="submit" variant="warn" icon="download" x-bind:disabled="!understood">{{ __('Update to latest') }}</x-ap-button>
                </div>
            </form>
        </x-ap-modal>
    @endif
</x-ap-page>
