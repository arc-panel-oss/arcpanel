<?php

namespace App\View;

/**
 * Hashed asset names from public/build/manifest.json, written by build.js
 * (docs/design/05 §7). Used through the @apAsset('app.js') Blade directive.
 */
final class AssetManifest
{
    /** @var array<string, string>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $manifestPath) {}

    /** URL of a built asset; the plain name when it is not in the manifest. */
    public function url(string $name): string
    {
        return asset('build/'.($this->entries()[$name] ?? $name));
    }

    /** @return array<string, string> */
    private function entries(): array
    {
        if ($this->entries === null) {
            $data = is_readable($this->manifestPath)
                ? json_decode((string) file_get_contents($this->manifestPath), true)
                : null;
            $this->entries = is_array($data) ? array_filter($data, 'is_string') : [];
        }

        return $this->entries;
    }
}
