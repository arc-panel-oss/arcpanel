<?php

use App\I18n\GettextCatalog;

it('reads singular, plural, multi-line and escaped entries from a .po file', function () {
    $po = <<<'PO'
    msgid ""
    msgstr ""
    "Content-Type: text/plain; charset=UTF-8\n"

    #: web/templates/pages/login/login.php:7
    msgid "Username"
    msgstr "Tên đăng nhập"

    msgid "Welcome to %s"
    msgstr ""
    "Chào mừng đến "
    "%s"

    msgid "%d domain"
    msgid_plural "%d domains"
    msgstr[0] "%d Domain"
    msgstr[1] "%d Domains"

    #, fuzzy
    msgid "Fuzzy"
    msgstr "Unsure"

    msgid "Untranslated"
    msgstr ""

    msgid "Say \"hi\""
    msgstr "Sag \"hallo\""
    PO;

    expect(GettextCatalog::fromPo($po))->toBe([
        '%d domain' => '%d Domain|%d Domains',
        'Say "hi"' => 'Sag "hallo"',
        'Username' => 'Tên đăng nhập',
        'Welcome to %s' => 'Chào mừng đến %s',
    ]);
});

it('reads a compiled .mo file', function () {
    $entries = ['' => 'Content-Type: text/plain; charset=UTF-8', "%d domain\0%d domains" => "%d Domain\0%d Domains", 'Username' => 'Benutzername'];
    ksort($entries);
    $ids = array_keys($entries);
    $texts = array_values($entries);
    $count = count($entries);

    $originals = 28;
    $translations = $originals + $count * 8;
    $offset = $translations + $count * 8;
    $table1 = $table2 = $blob = '';
    foreach ($ids as $id) {
        $table1 .= pack('VV', strlen($id), $offset + strlen($blob));
        $blob .= $id."\0";
    }
    foreach ($texts as $text) {
        $table2 .= pack('VV', strlen($text), $offset + strlen($blob));
        $blob .= $text."\0";
    }
    $mo = pack('V7', 0x950412DE, 0, $count, $originals, $translations, 0, 0).$table1.$table2.$blob;

    expect(GettextCatalog::fromMo($mo))->toBe([
        '%d domain' => '%d Domain|%d Domains',
        'Username' => 'Benutzername',
    ]);
});

it('reads the catalogues shipped in lib/locale', function () {
    $mo = base_path('../lib/locale/de/LC_MESSAGES/hestiacp.mo');
    if (! is_file($mo)) {
        $this->markTestSkipped('lib/locale is not next to the panel app');
    }

    expect(GettextCatalog::fromFile($mo))->toHaveKey('Username');
});
