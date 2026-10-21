<?php

use App\I18n\GettextCatalog;
use Illuminate\Support\Facades\Artisan;

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
    $mo = base_path('../lib/locale/de/LC_MESSAGES/arcpanel.mo');
    if (! is_file($mo)) {
        $this->markTestSkipped('lib/locale is not next to the panel app');
    }

    expect(GettextCatalog::fromFile($mo))->toHaveKey('Username');
});

it('prints every language as one JSON object with --stdout and writes no lang file (E39 W5 L15)', function () {
    $dir = sys_get_temp_dir().'/arc-i18n-'.bin2hex(random_bytes(4));
    mkdir($dir.'/vi/LC_MESSAGES', 0700, true);
    file_put_contents($dir.'/vi/LC_MESSAGES/arcpanel.po', "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\nmsgid \"Username\"\nmsgstr \"Tên đăng nhập\"\n");
    $before = is_file(lang_path('vi.json')) ? file_get_contents(lang_path('vi.json')) : null;

    try {
        expect(Artisan::call('arcpanel:i18n-import', ['--locale-dir' => $dir, '--stdout' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true))->toBe(['vi' => ['Username' => 'Tên đăng nhập']])
            ->and(is_file(lang_path('vi.json')) ? file_get_contents(lang_path('vi.json')) : null)->toBe($before);
    } finally {
        unlink($dir.'/vi/LC_MESSAGES/arcpanel.po');
        rmdir($dir.'/vi/LC_MESSAGES');
        rmdir($dir.'/vi');
        rmdir($dir);
    }
});
