{{--
    Bulk actions of a table (replaces web/bulk/*): a POST form with one
    button per action; the selected rows join it as items[].
    actions: [value => [label, icon?, danger?, confirm?]]; "%d" in confirm
    is the number of selected rows.
--}}
@props(['table', 'action', 'actions' => []])
<form {{ $attributes->merge(['class' => 'ap-bulk-bar']) }} id="{{ $table }}-bulk" method="post" action="{{ $action }}"
    x-data="apBulkBar({ table: @js($table) })" x-show="active" x-cloak aria-label="{{ __('Bulk actions') }}">
    @csrf
    <b data-count><span x-text="count"></span> {{ __('selected') }}</b>
    @foreach ($actions as $value => $definition)
        <button type="submit" name="action" value="{{ $value }}" class="ap-btn ap-btn-sm{{ ($definition['danger'] ?? false) ? ' ap-btn-danger' : '' }}"
            @if ($definition['confirm'] ?? null) data-confirm="{{ $definition['confirm'] }}" data-confirm-accept="{{ $definition['label'] }}" @if ($definition['danger'] ?? false) data-confirm-danger @endif @endif>
            @if ($definition['icon'] ?? null)<x-ap-icon :name="$definition['icon']" />@endif{{ $definition['label'] }}
        </button>
    @endforeach
</form>
