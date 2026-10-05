{{-- Vue island mount point (docs/design/05 §5.2); the loader in app.js imports the chunk. --}}
@props(['name', 'props' => []])
<div {{ $attributes }} data-island="{{ $name }}" data-props="{{ json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) }}">{{ $slot }}</div>
