{{-- Side panel dialog; same behaviour as x-ap-modal. --}}
@props(['id', 'title', 'description' => null, 'footer' => null])
<x-ap-modal :id="$id" :title="$title" :description="$description" :footer="$footer" :drawer="true" {{ $attributes }}>{{ $slot }}</x-ap-modal>
