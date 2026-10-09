<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Http\Controllers\Files\FilePageController;

// File manager page (E3 spec §7): hostco owns bob; resb owns carol and dave.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake, ['dave' => ['SUSPENDED' => 'yes']]);
    $this->fake->on('v-list-fs-directory-json')->using(fn (array $args) => [0, (string) json_encode(['TOTAL' => 2, 'OFFSET' => 0, 'ENTRIES' => [
        ['NAME' => 'public_html', 'TYPE' => 'd', 'SIZE' => '4096', 'MODE' => '0755', 'OWNER' => $args[0], 'GROUP' => $args[0], 'MTIME' => 1, 'LINK' => '', 'LINK_TYPE' => '', 'LINK_INSIDE' => '', 'VALID_NAME' => 'yes'],
        ['NAME' => 'notes.txt', 'TYPE' => 'f', 'SIZE' => '1234', 'MODE' => '0644', 'OWNER' => $args[0], 'GROUP' => $args[0], 'MTIME' => 1, 'LINK' => '', 'LINK_TYPE' => '', 'LINK_INSIDE' => '', 'VALID_NAME' => 'yes'],
    ]])]);
    $this->fake->on('v-get-fs-file-info')->using(fn (array $args) => [0, (string) json_encode(['NAME' => basename($args[1]), 'TYPE' => 'd', 'MODE' => '0755', 'WRITABLE' => 'no'])]);
});

it('renders the folder on the server and mounts the island', function () {
    signIn('bob', 'user');

    $response = $this->get('/files/bob?path=web/example.com')->assertOk()
        ->assertSee('data-island="file-manager"', false)
        ->assertSee('public_html/')
        ->assertSee('notes.txt')
        ->assertDontSee('Browsing the files of');
    $props = json_decode(html_entity_decode((string) preg_replace('/.*data-props="([^"]*)".*/s', '$1', $response->getContent())), true);
    expect($props['listing']['path'])->toBe('web/example.com')
        ->and($props['abilities'])->toBe(['manage' => true])
        ->and($props['limits']['uploadMax'])->toBe(4 * 1073741824)
        ->and($props['limits']['chunkSize'])->toBe(8388608)
        ->and($props['strings'])->toHaveKey('Upload');
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a[1] === '/home/bob/web/example.com');
});

it('sends /files to the effective account and keeps the path', function () {
    signIn('bob', 'user');
    $this->get('/files')->assertRedirect('/files/bob');
    $this->get('/files?path=web')->assertRedirect('/files/bob?path=web');
});

it('shows a banner when an administrator or reseller browses a customer', function () {
    signIn('hostco', 'reseller');
    $this->get('/files/bob')->assertOk()->assertSee('Browsing the files of bob')->assertSee('/home/bob');
    $this->get('/files/carol')->assertNotFound();

    signIn('resb', 'reseller');
    $response = $this->get('/files/dave')->assertOk()->assertSee('The account is suspended.');
    expect($response->getContent())->toContain('&quot;manage&quot;:false');

    signIn('arcadmin', 'admin');
    $this->get('/files/carol')->assertOk()->assertSee('Owner resb');
});

it('opens a link to a file in its folder with the editor, and 404 for what does not exist', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-fs-directory-json', fn ($a) => str_ends_with($a[1], 'index.php'))->returnsExit(ExitCode::NotExist, 'Error: not a directory');
    $this->fake->on('v-get-fs-file-info', fn ($a) => str_ends_with($a[1], 'index.php'))->returnsJson(['NAME' => 'index.php', 'TYPE' => 'f']);
    $this->get('/files/bob?path=web/index.php')->assertRedirect('/files/bob?path=web&edit=web%2Findex.php');

    $this->fake->on('v-list-fs-directory-json', fn ($a) => str_ends_with($a[1], 'missing'))->returnsExit(ExitCode::NotExist);
    $this->fake->on('v-get-fs-file-info', fn ($a) => str_ends_with($a[1], 'missing'))->returnsExit(ExitCode::NotExist);
    $this->get('/files/bob?path=missing')->assertNotFound();
    $this->get('/files/bob?path=../carol')->assertNotFound();
});

it('sends a folder the account cannot read back to its parent with a message', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-fs-directory-json', fn ($a) => str_ends_with($a[1], '/mail'))->returnsExit(ExitCode::Forbidden, 'Error: /home/bob/mail is not readable');
    $this->get('/files/bob?path=mail')->assertRedirect('/files/bob')->assertSessionHas('error', 'You cannot open this folder.');

    $this->fake->on('v-list-fs-directory-json', fn ($a) => str_ends_with($a[1], '/conf/dns'))->returnsExit(ExitCode::Forbidden);
    $this->get('/files/bob?path=conf/dns')->assertRedirect('/files/bob?path=conf');

    // The home folder has no parent: the refusal stays
    $this->fake->on('v-list-fs-directory-json', fn ($a) => $a[1] === '/home/bob')->returnsExit(ExitCode::Forbidden);
    $this->get('/files/bob')->assertForbidden();
});

it('answers 404 when the file manager is off', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['FILE_MANAGER' => 'false']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    signIn('bob', 'user');

    $this->get('/files')->assertNotFound();
    $this->get('/files/bob')->assertNotFound();
});

it('lists every string the island uses', function () {
    $missing = [];
    foreach (glob(resource_path('js/islands/file-manager/*.{vue,js}'), GLOB_BRACE) ?: [] as $file) {
        preg_match_all("/\\bt\\(\\s*'((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents($file), $matches);
        foreach ($matches[1] as $key) {
            $key = str_replace("\\'", "'", $key);
            if (! in_array($key, FilePageController::STRINGS, true)) {
                $missing[] = basename($file).': '.$key;
            }
        }
    }
    expect($missing)->toBe([]);
});
