{{-- Owner picker (administrators and resellers) and the tabs of an account's backups (E48 spec §8.1). --}}
@if ($owners !== null && count($owners) > 1)
    <form method="get" action="{{ route('backups.index') }}" class="ap-row ap-owner-pick">
        <x-ap-select name="owner" :label="__('Owner')" :options="array_combine($owners, $owners)" :value="$user" id="pick-owner" data-ap-autosubmit />
        <x-ap-button type="submit" size="sm" class="ap-no-js">{{ __('Show backups') }}</x-ap-button>
    </form>
@endif
@php
    $tabs = [
        'overview' => [route('backups.overview', ['user' => $user]), __('Overview')],
        'archives' => [route('backups.archives.index', ['user' => $user]), __('Archives')],
        'snapshots' => [route('backups.snapshots.index', ['user' => $user]), __('Snapshots')],
    ];
    if ($can['history'] ?? true) {
        $tabs['history'] = [route('backups.history', ['user' => $user]), __('History')];
    }
    $tabs['exclusions'] = [route('backups.exclusions.edit', ['user' => $user]), __('Exclusions')];
@endphp
<nav class="ap-tabs" aria-label="{{ __('Backups') }}">
    @foreach ($tabs as $key => [$href, $label])
        <a class="ap-tab" href="{{ $href }}" @if ($current === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
