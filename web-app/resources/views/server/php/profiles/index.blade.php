{{-- PHP-FPM profiles (E41 spec §7, §8): named pool settings that packages allow and web domains choose. --}}
@php
    $pmLabel = ['ondemand' => __('On demand'), 'dynamic' => __('Dynamic'), 'static' => __('Static')];
@endphp
<x-ap-page :title="__('PHP-FPM profiles')" :crumbs="[['label' => __('Server')], ['label' => __('PHP'), 'url' => route('server.php.index')], ['label' => __('PHP-FPM profiles')]]"
    :description="__('A profile sets the process manager and the PHP limits of a pool. Packages choose the profiles their web domains may use.')">
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button :href="route('server.php.profiles.create')" variant="primary" icon="plus">{{ __('Add profile') }}</x-ap-button>
        </x-slot:actions>
    @endif

    <x-ap-card :title="__('Profiles')" icon="sliders" flush>
        <x-ap-table id="php-profiles" :searchable="false" :caption="__('PHP-FPM profiles')" :columns="[
            ['key' => 'name', 'label' => __('Name')],
            ['key' => 'pm', 'label' => __('Process manager')],
            ['key' => 'children', 'label' => __('Processes'), 'type' => 'number'],
            ['key' => 'memory', 'label' => __('Memory limit')],
            ['key' => 'domains', 'label' => __('Web domains'), 'type' => 'number'],
            ['key' => 'packages', 'label' => __('Packages')],
        ]">
            @foreach ($profiles as $name => $profile)
                @php($v = $profile['values'])
                <tr data-row data-profile="{{ $name }}">
                    <td data-col="name" data-label="{{ __('Name') }}">
                        <a class="mono" href="{{ route('server.php.profiles.edit', ['profile' => $name]) }}"><b>{{ $name }}</b></a>
                        @if ($profile['builtin'])
                            <x-ap-badge>{{ __('Shipped') }}</x-ap-badge>
                        @endif
                        @if ($v['DESCRIPTION'] !== '')
                            <small class="muted">{{ $v['DESCRIPTION'] }}</small>
                        @endif
                    </td>
                    <td data-col="pm" data-label="{{ __('Process manager') }}">{{ $pmLabel[$v['PM']] ?? $v['PM'] }}</td>
                    <td data-col="children" data-label="{{ __('Processes') }}" class="col-num mono">{{ $v['MAX_CHILDREN'] }}</td>
                    <td data-col="memory" data-label="{{ __('Memory limit') }}" class="mono">{{ $v['MEMORY_LIMIT'] !== '' ? $v['MEMORY_LIMIT'] : __('from php.ini') }}</td>
                    <td data-col="domains" data-label="{{ __('Web domains') }}" class="col-num mono">{{ $profile['domains'] }}</td>
                    <td data-col="packages" data-label="{{ __('Packages') }}" class="muted">{{ $profile['packages'] !== [] ? implode(', ', $profile['packages']) : '—' }}</td>
                    <td class="col-actions">
                        @if ($canManage && $name !== 'default')
                            @if ($profile['domains'] > 0 || $profile['packages'] !== [])
                                <form method="post" action="{{ route('server.php.profiles.destroy', ['profile' => $name]) }}" data-confirm-danger
                                    data-confirm="{{ sprintf(__('Profile %1$s is used by %2$d web domains and named by packages %3$s. Delete it and move them to the default profile?'), $name, $profile['domains'], implode(', ', $profile['packages']) ?: '—') }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="force" value="1">
                                    <x-ap-button type="submit" size="sm" icon="trash">{{ __('Delete') }}</x-ap-button>
                                </form>
                            @else
                                <x-ap-button size="sm" icon="trash" :form-action="route('server.php.profiles.destroy', ['profile' => $name])" form-method="DELETE"
                                    :confirm="sprintf(__('Delete profile %s?'), $name)">{{ __('Delete') }}</x-ap-button>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ap-table>
    </x-ap-card>
</x-ap-page>
