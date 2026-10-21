<?php

// The panel's Content Security Policy allows no inline script (E39 W5 spec
// §9.2): views carry no <script> without src except JSON data blocks, no
// on* event attributes, and raw output only where it is allow-listed (L22).

/** @return array<string, string> view path relative to resources/views => contents */
function cspViews(): array
{
    $root = resource_path('views');
    $views = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $views[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($views);

    return $views;
}

it('has no inline script in any view, only JSON data blocks', function () {
    $found = [];
    foreach (cspViews() as $view => $content) {
        preg_match_all('/<script\b[^>]*>/i', $content, $tags);
        foreach ($tags[0] as $tag) {
            if (! preg_match('/\bsrc=/i', $tag) && ! preg_match('/type="application\/json"/i', $tag)) {
                $found[] = "$view: $tag";
            }
        }
    }

    expect($found)->toBe([]);
});

it('has no inline event handler attribute in any view', function () {
    $found = [];
    foreach (cspViews() as $view => $content) {
        if (preg_match_all('/\son[a-z]+\s*=\s*["\']/i', $content, $m)) {
            $found[] = "$view: ".implode(', ', array_map('trim', $m[0]));
        }
    }

    expect($found)->toBe([]);
});

it('prints raw HTML only in the allow-listed views', function () {
    $allowed = [
        // "Logged in as :user": the translation is escaped, the escaped name goes in <b> afterwards
        'components/ap/shell.blade.php',
    ];
    $found = [];
    foreach (cspViews() as $view => $content) {
        if (str_contains($content, '{!!') && ! in_array($view, $allowed, true)) {
            $found[] = $view;
        }
    }

    expect($found)->toBe([]);
});
