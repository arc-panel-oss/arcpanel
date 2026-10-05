{{-- Access and error log of a web domain (web/list/web-log, E1-S2 spec §8.5). --}}
@php($params = ['user' => $record->user, 'domain' => $record->domain])
<x-ap-page :title="$type === 'error' ? __('Error Log') : __('Access Log')"
    :crumbs="[['label' => __('Web domains'), 'url' => route('web.domains.index')], ['label' => $record->domain, 'url' => route('web.domains.edit', $params)], ['label' => $type === 'error' ? __('Error Log') : __('Access Log')]]"
    :eyebrow="$record->domain" :description="__('The last lines of the log. Refresh to load new lines.')">
    <nav class="ap-tabs" aria-label="{{ __('Logs') }}">
        <a class="ap-tab" href="{{ route('web.domains.logs', $params + ['type' => 'access']) }}" @if ($type === 'access') aria-current="page" @endif>{{ __('Access Log') }}</a>
        <a class="ap-tab" href="{{ route('web.domains.logs', $params + ['type' => 'error']) }}" @if ($type === 'error') aria-current="page" @endif>{{ __('Error Log') }}</a>
    </nav>
    <x-ap-log :lines="$lines" :title="$record->domain.' · '.$type" :url="route('web.domains.logs', $params + ['type' => $type])" :download="$download" />
</x-ap-page>
