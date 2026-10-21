{{-- Owner picker (administrators and resellers) and the Backups / Snapshots / Exclusions tabs. --}}
@if ($owners !== null && count($owners) > 1)
    <form method="get" action="{{ route('backups.index') }}" class="ap-row ap-owner-pick">
        <x-ap-select name="owner" :label="__('Owner')" :options="array_combine($owners, $owners)" :value="$user" id="pick-owner" data-ap-autosubmit />
        <x-ap-button type="submit" size="sm" class="ap-no-js">{{ __('Show backups') }}</x-ap-button>
    </form>
@endif
<nav class="ap-tabs" aria-label="{{ __('Backups') }}">
    <a class="ap-tab" href="{{ route('backups.archives.index', ['user' => $user]) }}" @if ($current === 'archives') aria-current="page" @endif>{{ __('Backups') }}</a>
    @if ($incremental)
        <a class="ap-tab" href="{{ route('backups.snapshots.index', ['user' => $user]) }}" @if ($current === 'snapshots') aria-current="page" @endif>{{ __('Snapshots') }}</a>
    @endif
    <a class="ap-tab" href="{{ route('backups.exclusions.edit', ['user' => $user]) }}" @if ($current === 'exclusions') aria-current="page" @endif>{{ __('Backup Exclusions') }}</a>
</nav>
