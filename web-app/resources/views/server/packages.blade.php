{{-- System packages of the catalog (E5, spec 2026-10-06-006 §7). --}}
@php
    $titles = ['runtime' => __('Runtimes'), 'media' => __('Media'), 'tooling' => __('Tools')];
    $runtimeLabels = ['python' => 'Python', 'go' => 'Go', 'ruby' => 'Ruby'];
@endphp
<x-ap-page :title="__('System packages')" :crumbs="[['label' => __('Server')], ['label' => __('System packages')]]"
    :description="__('Runtimes and tools from the distribution\'s archive, for apps and templates that need them.')">
    <x-ap-task-panel :task="$task" :title="__('Installing')"
        :done-message="__('The task has finished.')" :fail-message="__('The task failed.')">
        @forelse ($groups as $category => $entries)
            <x-ap-card :title="$titles[$category] ?? $category" icon="package">
                <ul class="ap-picker ap-store" role="list">
                    @foreach ($entries as $entry)
                        <li>
                            <div class="ap-pick" data-package="{{ $entry['slug'] }}">
                                <b>{{ $entry['name'] }}</b>
                                <span>{{ $entry['summary'] }}</span>
                                <span class="ap-pick-foot">
                                    @if (! $entry['available'])
                                        <x-ap-badge>{{ __('Not available on this release') }}</x-ap-badge>
                                    @elseif ($entry['status'] === 'installed')
                                        <x-ap-badge tone="ok">{{ $entry['source'] === 'system' ? __('Installed by the system') : __('Installed') }}</x-ap-badge>
                                    @elseif ($entry['status'] === 'partial')
                                        <x-ap-badge tone="warn">{{ __('Partly installed') }}</x-ap-badge>
                                    @else
                                        <x-ap-badge>{{ __('Not installed') }}</x-ap-badge>
                                    @endif
                                    @if ($entry['version'] !== '')
                                        <small class="mono">{{ $entry['version'] }}</small>
                                    @endif
                                    @if ($entry['upgradable'])
                                        <x-ap-badge tone="info">{{ __('Update available') }}</x-ap-badge>
                                    @endif
                                </span>
                                @if ($entry['provides'] !== [])
                                    <small class="muted">{{ sprintf(__('Provides: %s'), implode(', ', array_map(fn ($r) => $runtimeLabels[$r] ?? $r, $entry['provides']))) }}</small>
                                @endif
                                @if ($entry['usedBy'] > 0)
                                    <small class="muted" title="{{ implode(', ', $entry['domains']) }}">{{ trans_choice('Used by :count web domain|Used by :count web domains', $entry['usedBy'], ['count' => $entry['usedBy']]) }}</small>
                                @endif
                                @if ($canManage)
                                    <span class="ap-row">
                                        @if ($entry['available'] && $entry['status'] !== 'installed')
                                            <form method="post" action="{{ route('server.packages.start', ['slug' => $entry['slug'], 'action' => 'add']) }}"
                                                data-confirm="{{ sprintf(__('Install %s from the distribution\'s archive now?'), $entry['name']) }}">
                                                @csrf
                                                <x-ap-button type="submit" size="sm" variant="primary" icon="download" x-bind:disabled="running">{{ __('Install') }}</x-ap-button>
                                            </form>
                                        @endif
                                        @if ($entry['source'] === 'arcpanel' && $entry['usedBy'] > 0)
                                            <x-ap-button size="sm" icon="trash" disabled :title="__('Needed by web domains')">{{ __('Remove') }}</x-ap-button>
                                        @elseif ($entry['source'] === 'arcpanel')
                                            <form method="post" action="{{ route('server.packages.start', ['slug' => $entry['slug'], 'action' => 'delete']) }}"
                                                data-confirm="{{ sprintf(__('Remove %s? Packages that were on the server before stay installed.'), $entry['name']) }}">
                                                @csrf
                                                <x-ap-button type="submit" size="sm" icon="trash" x-bind:disabled="running">{{ __('Remove') }}</x-ap-button>
                                            </form>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ap-card>
        @empty
            <x-ap-card :title="__('System packages')" icon="package">
                <x-ap-empty icon="package" :title="__('No system packages')" />
            </x-ap-card>
        @endforelse
    </x-ap-task-panel>
</x-ap-page>
