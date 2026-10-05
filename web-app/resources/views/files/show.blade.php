{{-- File manager of an account (E3 spec §7.1); the file-manager island takes over the content. --}}
<x-ap-page :title="__('Files')" :crumbs="[['label' => __('Files')], ['label' => $user]]" :eyebrow="__('File manager · :user', ['user' => $user])">
    @if ($foreign)
        <x-ap-alert tone="warn" :title="__('Browsing the files of :user', ['user' => $user])">
            {{ $owner !== '' ? __('Owner :owner', ['owner' => $owner]).' · ' : '' }}<span class="mono">{{ $home }}</span>. {{ __('Changes run as :user.', ['user' => $user]) }}
            @if ($suspended)
                {{ __('The account is suspended.') }}
            @endif
        </x-ap-alert>
    @endif
    <x-ap-island name="file-manager" :props="$props" class="ap-stack">
        {{-- Without scripts: the folder's entries --}}
        <x-ap-card :title="'~/'.$path->relative()" icon="folder" :flush="true">
            @if ($listing['entries'] === [])
                <x-ap-empty icon="folder" :title="__('This folder is empty.')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <caption class="sr-only">{{ __('Files') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col" class="col-num">{{ __('Size') }}</th>
                                <th scope="col">{{ __('Permissions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($listing['entries'] as $entry)
                                <tr>
                                    <td data-label="{{ __('Name') }}">
                                        @if ($entry->isDirectory() && $entry->validName)
                                            <a class="mono" href="{{ route('files.show', ['user' => $user, 'path' => $path->child($entry->name)->relative()]) }}">{{ $entry->name }}/</a>
                                        @else
                                            <span class="mono">{{ $entry->name }}</span>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('Size') }}" class="col-num mono">{{ $entry->isDirectory() ? '—' : number_format($entry->size) }}</td>
                                    <td data-label="{{ __('Permissions') }}" class="mono">{{ $entry->mode }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
