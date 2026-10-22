{{--
    The restore wizard of an account (E48 spec §8.1): 1. the archive or
    snapshot, 2. what to restore (a section per kind with select all and a
    filter, dotfiles folded into "Hidden files"), 3. confirm. The restore
    is queued by the archive and snapshot restore routes.
--}}
@use('App\Http\Controllers\Backups\RestoreController')
@php
    $chosen = $archive ?? $snapshot;
    $isSnapshot = $snapshot !== null;
    $sections = $items !== null ? RestoreController::sections($items, $systems, $isSnapshot) : [];
    $action = $archive !== null
        ? route('backups.archives.restore', ['user' => $user, 'backup' => $archive->name])
        : ($snapshot !== null ? route('backups.snapshots.restore', ['user' => $user, 'snapshot' => $snapshot->shortId]) : route('backups.restore', ['user' => $user]));
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
@endphp
<x-ap-page :title="__('Restore')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.overview', ['user' => $user])], ['label' => __('Restore')]]"
    :eyebrow="__('Owner').': '.$user">
    @include('backups.partials.nav', ['current' => ''])

    <x-ap-form :action="$action">
        <input type="hidden" name="wizard" value="1">
        <x-ap-wizard :steps="[__('Backup'), __('What to restore'), __('Confirm')]" :start="$chosen !== null ? 1 : 0" :submit-label="__('Restore')">
            <fieldset data-step class="ap-fieldset-plain ap-stack">
                <x-ap-card :title="__('Archives')" icon="archive" flush>
                    @if ($archives === [])
                        <x-ap-empty icon="archive" :title="__('No archives yet')" />
                    @else
                        <ul class="ap-list" data-pick-archives>
                            @foreach ($archives as $each)
                                <li>
                                    <div class="ap-list-main">
                                        <a class="mono" href="{{ route('backups.restore', ['user' => $user, 'archive' => $each->name]) }}"
                                            @if ($archive?->name === $each->name) aria-current="true" @endif><b>{{ $each->date }} {{ $each->time }}</b></a>
                                        <span>{{ $each->name }} · {{ $size($each->sizeMb) }}@if ($each->encrypted) · {{ __('encrypted') }}@endif</span>
                                    </div>
                                    <span class="ap-row">@foreach ($each->destinations as $name)<x-ap-badge mono>{{ $name }}</x-ap-badge>@endforeach</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ap-card>
                @if ($incremental)
                    <x-ap-card :title="__('Snapshots')" icon="history" flush>
                        @if ($snapshots === [])
                            <x-ap-empty icon="history" :title="__('No snapshots yet')" />
                        @else
                            <ul class="ap-list" data-pick-snapshots>
                                @foreach ($snapshots as $each)
                                    <li>
                                        <div class="ap-list-main">
                                            <a class="mono" href="{{ route('backups.restore', ['user' => $user, 'snapshot' => $each->shortId]) }}"
                                                @if ($snapshot?->shortId === $each->shortId) aria-current="true" @endif><b>{{ $each->label() }}</b></a>
                                            <span class="mono">{{ $each->shortId }}</span>
                                        </div>
                                        @if ($each->destination !== '')<x-ap-badge mono>{{ $each->destination }}</x-ap-badge>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ap-card>
                @endif
            </fieldset>

            <fieldset data-step class="ap-fieldset-plain ap-stack"
                x-data="{ mode: 'all', query: '', matches(name) { return this.query === '' || name.toLowerCase().includes(this.query.toLowerCase()); } }">
                @if ($chosen === null)
                    <p class="muted">{{ __('Pick the archive or snapshot to restore from first.') }}</p>
                @else
                    <x-ap-card>
                        <p><b class="mono">{{ $archive?->name ?? sprintf(__('Snapshot %s'), $snapshot->shortId) }}</b>
                            <span class="muted">{{ $archive !== null ? $archive->date.' '.$archive->time : $snapshot->label() }}</span></p>
                        <div class="ap-row ap-radio-row">
                            <label class="ap-check"><input type="radio" name="mode" value="all" x-model="mode"><span>{{ __('Everything in it') }}</span></label>
                            <label class="ap-check"><input type="radio" name="mode" value="some" x-model="mode"><span>{{ $isSnapshot ? __('One item') : __('Some items') }}</span></label>
                        </div>
                        <div x-show="mode === 'some'" x-cloak class="ap-stack">
                            @if ($isSnapshot)
                                <p class="muted">{{ __('A snapshot restores everything or one item at a time; single files are restored from the file manager.') }}</p>
                            @endif
                            <label class="ap-field"><span class="ap-label">{{ __('Filter') }}</span>
                                <input class="ap-input" type="search" x-model="query" autocomplete="off"></label>
                        </div>
                    </x-ap-card>
                    @foreach ($sections as $type => $label)
                        @php($list = $type === 'cron' ? array_slice($items[$type], 0, 1) : $items[$type])
                        @php([$visible, $hidden] = $type === 'udir' ? RestoreController::splitHidden($list) : [$list, []])
                        <x-ap-card :title="$label" flush x-show="mode === 'some'" x-cloak data-restore-section="{{ $type }}">
                            @if (! $isSnapshot && $list !== [])
                                <x-slot:actions>
                                    <label class="ap-check"><input type="checkbox" data-select-all
                                        x-on:change="$el.closest('section').querySelectorAll('input[data-item]').forEach((box) => { box.checked = $el.checked })"><span>{{ __('Select all') }}</span></label>
                                </x-slot:actions>
                            @endif
                            @if ($list === [])
                                <p class="muted ap-card-pad">{{ $isSnapshot ? __('Nothing of this kind in the snapshot.') : __('Nothing of this kind in the backup.') }}</p>
                            @endif
                            @foreach (array_filter([$visible, $hidden]) as $group)
                                @if ($group === $hidden)
                                    <details class="ap-disclosure-plain ap-card-pad" data-hidden-files><summary>{{ trans_choice('Hidden files (:count)|Hidden files (:count)', count($hidden), ['count' => count($hidden)]) }}</summary>
                                @endif
                                <ul class="ap-list">
                                    @foreach ($group as $item)
                                        @php($text = $type === 'cron' ? __('All cron jobs') : $item)
                                        <li x-show="matches(@js($text))">
                                            <label class="ap-check">
                                                @if ($isSnapshot)
                                                    <input type="radio" name="pick" value="{{ $type }}:{{ $item }}" data-item>
                                                @else
                                                    <input type="checkbox" name="items[{{ $type }}][]" value="{{ $item }}" data-item>
                                                @endif
                                                <span class="mono">{{ $text }}</span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($group === $hidden)
                                    </details>
                                @endif
                            @endforeach
                        </x-ap-card>
                    @endforeach
                @endif
            </fieldset>

            <fieldset data-step class="ap-fieldset-plain ap-stack">
                <x-ap-alert tone="warn" :title="__('Restoring replaces what is there now')">
                    {{ __('The chosen websites, mail, databases, cron jobs and folders are replaced with their state in the backup. The restore is queued; you get an e-mail when it is done.') }}
                </x-ap-alert>
            </fieldset>
        </x-ap-wizard>
    </x-ap-form>
</x-ap-page>
