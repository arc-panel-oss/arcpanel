{{-- Search results (web/search, E7 batch 3 spec §12.1): the groups of the palette search, up to 50 per group. --}}
<x-ap-page :title="__('Search')" :crumbs="[['label' => __('Search')]]">
    <x-ap-card>
        <form method="get" action="{{ route('search') }}" class="ap-row" role="search">
            <x-ap-input name="q" :label="__('Search')" :value="$query" icon="search" required minlength="2" maxlength="100" autofocus />
            <x-ap-button type="submit" variant="primary" icon="search">{{ __('Search') }}</x-ap-button>
        </form>
    </x-ap-card>

    @if (mb_strlen($query) < 2)
        <x-ap-card><x-ap-empty icon="search" :title="__('Type at least 2 characters')" /></x-ap-card>
    @elseif ($groups === [])
        <x-ap-card><x-ap-empty icon="search" :title="__('No results')">{{ sprintf(__('Nothing matches %s.'), $query) }}</x-ap-empty></x-ap-card>
    @else
        @foreach ($groups as $group)
            <x-ap-card :title="$group['label']" :flush="true">
                <table class="ap-table">
                    <caption class="sr-only">{{ $group['label'] }}</caption>
                    <thead><tr><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('Owner') }}</th></tr></thead>
                    <tbody>
                        @foreach ($group['items'] as $item)
                            <tr>
                                <td><a href="{{ $item['url'] }}"><x-ap-icon :name="$item['icon']" /> {{ $item['label'] }}</a></td>
                                <td class="muted">{{ $item['meta'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ap-card>
        @endforeach
    @endif
</x-ap-page>
