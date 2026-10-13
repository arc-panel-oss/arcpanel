<?php

use App\View\ShellComposer;

// Language selects show names, not codes, also without the intl extension.

it('names a language in that language', function () {
    expect(ShellComposer::languageName('en'))->toBe('English')
        ->and(ShellComposer::languageName('de'))->toBe('Deutsch')
        ->and(ShellComposer::languageName('vi'))->toBe('Tiếng Việt')
        ->and(ShellComposer::languageName('pt-br'))->toBe('Português (Brasil)')
        ->and(ShellComposer::languageName('zh-cn'))->toBe('简体中文');
});

it('falls back to the upper-case code for an unknown language', function () {
    expect(ShellComposer::languageName('xx'))->toBe('XX');
});

it('labels the options of a language select with the name and the code', function () {
    expect(ShellComposer::languageOptions(['en', 'vi', 'xx']))->toBe([
        'en' => 'English (en)',
        'vi' => 'Tiếng Việt (vi)',
        'xx' => 'xx',
    ]);
});

it('lists the installed translations by name for the user menu', function () {
    $languages = ShellComposer::languages();

    expect($languages['en'])->toBe('English');
    foreach ($languages as $code => $name) {
        expect($name)->not->toBe(strtoupper($code));
    }
});
