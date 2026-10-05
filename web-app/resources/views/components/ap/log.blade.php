{{--
    Log view (apLog): the last lines, monospace, with refresh (GET url as
    JSON) and an optional download link. Live follow arrives with E14.
--}}
@props(['lines' => [], 'title' => null, 'url' => null, 'download' => null])
<div {{ $attributes->merge(['class' => 'ap-log']) }} x-data="apLog({ url: @js($url) })" x-bind:class="{ 'is-wrapped': wrap }">
    <div class="ap-log-head">
        @if ($title)<span class="mono muted ap-log-title">{{ $title }}</span>@endif
        <div class="ap-topbar-spacer"></div>
        <span class="ap-error" x-show="error" x-text="error" x-cloak role="alert"></span>
        <label class="ap-check ap-log-wrap" x-cloak><input type="checkbox" x-model="wrap"><span>{{ __('Wrap lines') }}</span></label>
        @if ($url)
            <button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Refresh') }}" title="{{ __('Refresh') }}" x-on:click="refresh()" x-bind:disabled="loading" x-cloak><x-ap-icon name="refresh" /></button>
        @endif
        @if ($download)
            <a class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" href="{{ $download }}" aria-label="{{ __('Download') }}" title="{{ __('Download') }}"><x-ap-icon name="download" /></a>
        @endif
    </div>
    <div class="ap-log-body" x-ref="body" tabindex="0" role="log" aria-label="{{ $title ?? __('Log') }}">
        @forelse ($lines as $line)
            <div class="ap-log-line {{ \App\View\LogLine::level($line) }}">{{ $line }}</div>
        @empty
            <div class="ap-log-line muted">{{ __('The log is empty.') }}</div>
        @endforelse
    </div>
</div>
