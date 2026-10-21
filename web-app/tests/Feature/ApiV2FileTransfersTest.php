<?php

use App\Arc\ArcCommandFake;
use App\Arc\ExitCode;
use App\Services\Files\FileStaging;
use App\Services\Files\UploadSessions;
use Illuminate\Testing\TestResponse;

// REST API v2 uploads and downloads (E34 batch 5 spec §4.3, §4.4, D50, D51).
// hostco owns bob; resb owns carol and dave, who is suspended. A name
// starting with "new" does not exist until an upload writes it.

/** A second access key of the same user (D50). */
const API_OTHER_KEY_ID = 'APIKEY9876543210wxyz';

const API_OTHER_SECRET = 'S3cret-zyxwvutsrqponmlkjihgfedcba9876543';

/**
 * Two access keys of $user with the same sets, API_KEY_ID and API_OTHER_KEY_ID.
 *
 * @param  list<string>  $sets
 * @return array{0: array<string, string>, 1: array<string, string>} the request headers of each key
 */
function apiTransferKeys(ArcCommandFake $fake, string $user, string $role, array $sets = []): array
{
    $commands = $sets === [] ? ['*'] : array_values(array_unique(array_merge(...array_map(apiSetCommands(...), $sets))));
    $keys = [API_KEY_ID => API_SECRET, API_OTHER_KEY_ID => API_OTHER_SECRET];
    $fake->on('v-check-access-key-permissions')->using(fn (array $args) => ($keys[$args[0]] ?? null) === $args[1]
        ? [0, (string) json_encode(['ACCESS_KEY_ID' => $args[0], 'USER' => $user, 'ROLE' => $role, 'PERMISSIONS' => $sets, 'COMMANDS' => $commands])]
        : [9, 'Error: Invalid secret key for key '.$args[0]]);

    return [
        ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET, 'Accept' => 'application/json'],
        ['Authorization' => 'Bearer '.API_OTHER_KEY_ID.':'.API_OTHER_SECRET, 'Accept' => 'application/json'],
    ];
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['bob' => ['U_DISK' => '100', 'DISK_QUOTA' => '200'], 'dave' => ['SUSPENDED' => 'yes']]);
    $this->staging = sys_get_temp_dir().'/arc-api-transfers-'.bin2hex(random_bytes(4));
    mkdir($this->staging.'/uploads', 0700, true);
    mkdir($this->staging.'/downloads', 0700, true);
    config(['arc.files_staging' => $this->staging]);

    $made = new ArrayObject;
    $this->fake->on('v-get-fs-file-info')->using(function (array $a) use ($made) {
        $name = basename($a[1]);
        if (str_starts_with($name, 'new') && ! isset($made[$a[1]])) {
            return [ExitCode::NotExist->value, "Error: {$a[1]} doesn't exist"];
        }

        return [0, (string) json_encode([
            'NAME' => $name, 'TYPE' => str_contains($name, '.') ? 'f' : 'd', 'SIZE' => '12', 'MODE' => '0644', 'OWNER' => $a[0], 'GROUP' => $a[0],
            'MTIME' => 1759658400, 'LINK' => '', 'LINK_TYPE' => '', 'LINK_INSIDE' => '', 'VALID_NAME' => 'yes', 'MIME' => 'text/plain', 'WRITABLE' => 'yes',
        ])];
    });
    $this->fake->on('v-add-fs-directory')->returns();
    // v-add-fs-upload removes the staged file and prints the final path
    $this->fake->on('v-add-fs-upload')->using(function (array $a) use ($made) {
        $final = $a[4] === 'rename' ? preg_replace('/(\.[^.\/]+)$/', ' (1)$1', $a[2]) : $a[2];
        $made[$final] = true;
        @unlink($a[1]);

        return [0, $final."\n"];
    });
    $this->fake->on('v-add-fs-download')->using(function (array $a) {
        file_put_contents($this->staging.'/downloads/'.$a[1], 'bytes');
        $name = count($a) > 3 || ! str_contains(basename($a[2]), '.') ? basename(count($a) > 3 ? dirname($a[2]) : $a[2]).'.zip' : basename($a[2]);
        $mime = match (pathinfo($name, PATHINFO_EXTENSION)) {
            'zip' => 'application/zip',
            'pdf' => 'application/pdf',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            default => 'text/plain',
        };

        return [0, (string) json_encode(['NAME' => $name, 'SIZE' => 5, 'MIME' => $mime])];
    });

    // A PUT of one chunk with a raw body
    $this->chunk = fn (string $uri, string $bytes, array $headers): TestResponse => $this->call(
        'PUT', $uri, [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/octet-stream']), $bytes,
    );
    // Start an upload of $size bytes into web/ and answer the token
    $this->start = fn (array $headers, string $name = 'new.bin', int $size = 1, string $user = 'bob'): string => (string) $this->postJson(
        "/api/v2/users/{$user}/files/uploads", ['path' => 'web', 'name' => $name, 'size' => $size, 'modified_at' => 42], $headers,
    )->assertOk()->json('data.token');
});

afterEach(function () {
    FileStaging::removeTree($this->staging);
});

it('uploads a file in chunks with a key holding only v2-files, and resumes', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $size = UploadSessions::CHUNK + 3;

    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.bin', 'size' => $size, 'modified_at' => 1759658400123], $headers)->assertOk()
        ->assertJsonPath('data.chunk_size', UploadSessions::CHUNK)
        ->assertJsonPath('data.chunks', 2)
        ->assertJsonPath('data.received', []);
    $token = (string) $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.bin', 'size' => $size, 'modified_at' => 1759658400123], $headers)
        ->json('data.token');
    expect($token)->toMatch('/^[a-f0-9]{32}$/');
    $uri = "/api/v2/users/bob/files/uploads/{$token}";

    ($this->chunk)("{$uri}/1", 'end', $headers)->assertNoContent();
    // The same file again resumes the session with what it holds
    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.bin', 'size' => $size, 'modified_at' => 1759658400123], $headers)->assertOk()
        ->assertJsonPath('data.token', $token)
        ->assertJsonPath('data.received', [1]);
    $this->postJson("{$uri}/finish", [], $headers)->assertUnprocessable()->assertJsonPath('error.code', 'E_INVALID');
    ($this->chunk)("{$uri}/0", 'short', $headers)->assertUnprocessable()->assertJsonPath('error.code', 'E_INVALID');
    ($this->chunk)("{$uri}/2", 'x', $headers)->assertUnprocessable();
    ($this->chunk)("{$uri}/0", str_repeat('a', UploadSessions::CHUNK), $headers)->assertNoContent();

    $response = $this->postJson("{$uri}/finish", [], $headers)->assertCreated()
        ->assertJsonPath('data.path', 'web/new.bin')
        ->assertJsonPath('data.type', 'file');
    expect($response->headers->get('Location'))->toEndWith('/api/v2/users/bob/files/info?path=web%2Fnew.bin');
    $this->fake->assertRan('v-add-fs-upload', fn ($a) => $a === ['bob', $this->staging."/uploads/{$token}/file", '/home/bob/web/new.bin', '0644', 'fail']);
    $this->fake->assertNotRan('v-add-fs-directory');
    expect(glob($this->staging.'/uploads/*'))->toBe([]);
    ($this->chunk)("{$uri}/0", 'x', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
});

it('creates the folders of a folder upload and keeps them below the folder', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $token = (string) $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.txt', 'size' => 2, 'relative_path' => 'site/css/new.txt'], $headers)
        ->assertOk()->json('data.token');
    ($this->chunk)("/api/v2/users/bob/files/uploads/{$token}/0", 'ab', $headers)->assertNoContent();
    $this->postJson("/api/v2/users/bob/files/uploads/{$token}/finish", [], $headers)->assertCreated()
        ->assertJsonPath('data.path', 'web/site/css/new.txt');
    $this->fake->assertRan('v-add-fs-directory', fn ($a) => $a === ['bob', '/home/bob/web/site/css']);
    $this->fake->assertRan('v-add-fs-upload', fn ($a) => $a[2] === '/home/bob/web/site/css/new.txt');

    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'x.txt', 'size' => 1, 'relative_path' => '../../x.txt'], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'x.txt', 'size' => 1, 'relative_path' => 'sub/y.txt'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['relative_path']]]);
    foreach (['a/b', '..', ''] as $name) {
        $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => $name, 'size' => 1], $headers)->assertUnprocessable();
    }
    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => '../../etc', 'name' => 'passwd', 'size' => 1], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'x.txt', 'size' => -1], $headers)->assertUnprocessable();
});

it('binds an upload session to the access key that opened it (D50)', function () {
    [$first, $second] = apiTransferKeys($this->fake, 'bob', 'user', ['v2-files']);
    $token = ($this->start)($first);
    $uri = "/api/v2/users/bob/files/uploads/{$token}";

    ($this->chunk)("{$uri}/0", 'x', $second)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->postJson("{$uri}/finish", [], $second)->assertNotFound();
    $this->deleteJson($uri, [], $second)->assertNotFound();
    // The same file through the other key opens its own session
    expect(($this->start)($second))->not->toBe($token);

    ($this->chunk)("{$uri}/0", 'x', $first)->assertNoContent();
    $this->deleteJson($uri, [], $first)->assertNoContent();
    expect(is_dir($this->staging.'/uploads/'.$token))->toBeFalse();
    $this->postJson("{$uri}/finish", [], $first)->assertNotFound();
    $this->fake->assertNotRan('v-add-fs-upload');
});

it('answers uploads inside the tree and 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->postJson("/api/v2/users/{$owner}/files/uploads", ['path' => 'web', 'name' => 'new.txt', 'size' => 1], $headers)->assertStatus($status);
    $this->postJson("/api/v2/users/{$owner}/files/download", ['paths' => ['web/a.txt']], $headers)->assertStatus($status);
    if ($status === 404) {
        foreach (['PUT' => '/0', 'POST' => '/finish', 'DELETE' => ''] as $method => $suffix) {
            $this->json($method, "/api/v2/users/{$owner}/files/uploads/".str_repeat('a', 32).$suffix, [], $headers)->assertNotFound();
        }
        $this->fake->assertNotRan('v-add-fs-download');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'reseller B, other tree' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('refuses a v2-read key before it opens a session, and lets it download', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.txt', 'size' => 1], $headers)->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.commands', ['v-add-fs-upload']);
    expect(glob($this->staging.'/uploads/*'))->toBe([]);
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/a.txt']], $headers)->assertOk()
        ->assertHeader('X-Accel-Redirect');
});

it('keeps uploads to a suspended account to administrators', function () {
    $this->postJson('/api/v2/users/dave/files/uploads', ['path' => 'web', 'name' => 'new.txt', 'size' => 1], apiAs($this->fake, 'resb', 'reseller'))->assertForbidden();
    $this->postJson('/api/v2/users/dave/files/download', ['paths' => ['web/a.txt']], apiAs($this->fake, 'resb', 'reseller'))->assertOk();
    ($this->start)(apiAs($this->fake, 'arcadmin', 'admin'), user: 'dave');
});

it('answers 404 for every upload and download while the file manager is off', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_MANAGER' => 'false']);
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.txt', 'size' => 1], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/a.txt']], $headers)->assertNotFound();
    $this->fake->assertNotRan('v-add-fs-download');
});

it('refuses uploads above the limit, over the quota and beyond the staging space', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $body = ['path' => 'web', 'name' => 'new.bin', 'size' => 2048];

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_MANAGER_UPLOAD_MAX' => '1K']);
    $this->postJson('/api/v2/users/bob/files/uploads', $body, $headers)->assertStatus(413)
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'Files larger than 1 KB cannot be uploaded.');
    $this->postJson('/api/v2/users/bob/files/uploads?dry_run=1', $body, $headers)->assertStatus(413);

    // bob uses 100 of 200 MB: 200 MB more is too much when quotas are enforced
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'DISK_QUOTA' => 'yes']);
    $big = ['path' => 'web', 'name' => 'new.bin', 'size' => 200 * 1048576];
    $this->postJson('/api/v2/users/bob/files/uploads', $big, $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'Disk quota exceeded.');
    $this->postJson('/api/v2/users/bob/files/uploads?dry_run=1', $big, $headers)->assertUnprocessable()->assertJsonPath('error.code', 'E_LIMIT');

    // More than the account may stage (E39 W5 §7), more than the staging file system holds
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_STAGING_LIMIT' => '1M']);
    $this->postJson('/api/v2/users/bob/files/uploads', ['path' => 'web', 'name' => 'new.bin', 'size' => 2 * 1048576], $headers)->assertStatus(507)
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'Uploads and downloads in progress for this account may hold at most 1 MB at a time; 0 B is in use.');
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_MANAGER_UPLOAD_MAX' => '100000000G', 'FILE_STAGING_LIMIT' => '100000000G']);
    $huge = ['path' => 'web', 'name' => 'new.bin', 'size' => 1024 ** 5 * 50];
    $this->postJson('/api/v2/users/bob/files/uploads', $huge, $headers)->assertStatus(507)->assertJsonPath('error.code', 'E_DISK');
    $this->postJson('/api/v2/users/bob/files/uploads?dry_run=1', $huge, $headers)->assertStatus(507);

    expect(glob($this->staging.'/uploads/*'))->toBe([]);
});

it('finishes with the conflict rules and keeps the session after a conflict', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $upload = function (string $name) use ($headers): string {
        $token = ($this->start)($headers, $name);
        ($this->chunk)("/api/v2/users/bob/files/uploads/{$token}/0", 'x', $headers)->assertNoContent();

        return "/api/v2/users/bob/files/uploads/{$token}";
    };

    $uri = $upload('a.txt');
    $this->postJson("{$uri}/finish", [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.conflicts', ['a.txt']);
    $this->postJson("{$uri}/finish", ['on_conflict' => 'merge'], $headers)->assertUnprocessable();
    $this->fake->assertNotRan('v-add-fs-upload');
    $this->postJson("{$uri}/finish", ['on_conflict' => 'overwrite'], $headers)->assertCreated()->assertJsonPath('data.path', 'web/a.txt');
    $this->fake->assertRan('v-add-fs-upload', fn ($a) => $a[2] === '/home/bob/web/a.txt' && $a[4] === 'overwrite');

    $this->postJson("{$upload('a.txt')}/finish", ['on_conflict' => 'rename'], $headers)->assertCreated()->assertJsonPath('data.path', 'web/a (1).txt');
    $this->fake->assertRan('v-add-fs-upload', fn ($a) => $a[4] === 'rename');

    // skip: the existing file stays and the upload is dropped
    $uri = $upload('a.txt');
    $this->postJson("{$uri}/finish", ['on_conflict' => 'skip'], $headers)->assertOk()->assertJsonPath('data.path', 'web/a.txt');
    $this->fake->assertRan('v-add-fs-upload', times: 2);
    $this->postJson("{$uri}/finish", [], $headers)->assertNotFound();
    // skip with nothing in the way writes the file
    $this->postJson("{$upload('new.txt')}/finish", ['on_conflict' => 'skip'], $headers)->assertCreated();
    $this->fake->assertRan('v-add-fs-upload', fn ($a) => $a[2] === '/home/bob/web/new.txt' && $a[4] === 'fail');
    expect(glob($this->staging.'/uploads/*'))->toBe([]);
});

it('plans a start and a finish without running them, and refuses a dry run of chunks', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->postJson('/api/v2/users/bob/files/uploads?dry_run=1', ['path' => 'web', 'name' => 'new.txt', 'size' => 1, 'relative_path' => 'css/new.txt'], $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.*.command', ['v-add-fs-directory', 'v-add-fs-upload'])
        ->assertJsonPath('data.steps.0.args', ['bob', '/home/bob/web/css'])
        ->assertJsonPath('data.steps.1.args.2', '/home/bob/web/css/new.txt');
    expect(glob($this->staging.'/uploads/*'))->toBe([]);

    $token = ($this->start)($headers, 'a.txt');
    $uri = "/api/v2/users/bob/files/uploads/{$token}";
    ($this->chunk)("{$uri}/0?dry_run=1", 'x', $headers)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['dry_run']]]);
    $this->deleteJson("{$uri}?dry_run=1", [], $headers)->assertUnprocessable();
    $this->postJson("{$uri}/finish?dry_run=1", [], $headers)->assertUnprocessable()->assertJsonPath('error.message', 'The upload is not complete.');
    ($this->chunk)("{$uri}/0", 'x', $headers)->assertNoContent();

    $this->postJson("{$uri}/finish?dry_run=1", [], $headers)->assertStatus(409)->assertJsonPath('error.conflicts', ['a.txt']);
    $this->postJson("{$uri}/finish?dry_run=1", ['on_conflict' => 'overwrite'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-fs-upload')
        ->assertJsonPath('data.steps.0.args', ['bob', $this->staging."/uploads/{$token}/file", '/home/bob/web/a.txt', '0644', 'overwrite']);
    $this->postJson("{$uri}/finish?dry_run=1", ['on_conflict' => 'skip'], $headers)->assertOk()->assertJsonPath('data.steps', []);

    $this->fake->assertNotRan('v-add-fs-upload');
    $this->fake->assertNotRan('v-add-fs-directory');
    expect(is_file($this->staging."/uploads/{$token}/chunks/0"))->toBeTrue();
});

it('limits chunks to 600 a minute per key, apart from the other calls', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $uri = '/api/v2/users/bob/files/uploads/'.($this->start)($headers).'/0';

    for ($i = 0; $i < 600; $i++) {
        ($this->chunk)($uri, 'x', $headers)->assertNoContent();
    }
    ($this->chunk)($uri, 'x', $headers)->assertStatus(429)
        ->assertJsonPath('error.code', 'E_RATE_LIMIT')
        ->assertHeader('Retry-After');
    // The 60 a minute of every other call are untouched
    $this->getJson('/api/v2/me', $headers)->assertOk();
});

it('sends one file with the headers of the page', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $response = $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/tệp a.txt']], $headers)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Request-Id');
    $accel = (string) $response->headers->get('X-Accel-Redirect');
    expect($accel)->toMatch('#^/_arc_files/[a-f0-9]{32}$#')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=\"t___p a.txt\"; filename*=UTF-8''t%E1%BB%87p%20a.txt")
        ->and($response->headers->get('Content-Security-Policy'))->toEndWith('; sandbox')
        ->and($response->getContent())->toBe('');
    $ticket = basename($accel);
    $this->fake->assertRan('v-add-fs-download', fn ($a) => $a === ['bob', $ticket, '/home/bob/web/tệp a.txt']);
    // The ticket belongs to the key; files:gc removes it after an hour
    expect(json_decode((string) file_get_contents($this->staging."/downloads/{$ticket}.json"), true))
        ->toMatchArray(['owner' => 'api:'.API_KEY_ID, 'user' => 'bob', 'name' => 'tệp a.txt']);

    $inline = fn (string $path) => $this->postJson('/api/v2/users/bob/files/download', ['paths' => [$path], 'inline' => true], $headers)->assertOk();
    expect($inline('web/a.txt')->headers->get('Content-Disposition'))->toStartWith('inline; ');
    $png = $inline('web/a.png');
    expect($png->headers->get('Content-Disposition'))->toStartWith('inline; ')
        ->and($png->headers->get('Content-Security-Policy'))->toEndWith('; sandbox');
    $pdf = $inline('web/a.pdf');
    expect($pdf->headers->get('Content-Disposition'))->toStartWith('inline; ')
        ->and($pdf->headers->get('Content-Security-Policy'))->not->toContain('sandbox');
    // SVG can carry scripts: never inline
    $svg = $inline('web/a.svg');
    expect($svg->headers->get('Content-Disposition'))->toStartWith('attachment; ')
        ->and($svg->headers->get('Content-Security-Policy'))->toEndWith('; sandbox');
});

it('sends a folder or several entries as a zip', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $response = $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/site', 'web/a.txt'], 'inline' => true], $headers)->assertOk()
        ->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="web.zip"');
    $this->fake->assertRan('v-add-fs-download', fn ($a) => array_slice($a, 2) === ['/home/bob/web/site', '/home/bob/web/a.txt']);
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/site']], $headers)->assertOk()
        ->assertHeader('Content-Type', 'application/zip');
});

it('refuses downloads outside the home and plans them in a dry run', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/a.txt', 'app/b.txt']], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['paths']]]);
    foreach ([['.'], [''], ['../../etc/passwd'], ['/etc/passwd']] as $paths) {
        $this->postJson('/api/v2/users/bob/files/download', ['paths' => $paths], $headers)->assertStatus(in_array($paths, [['']], true) ? 422 : 404);
    }
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => []], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/a.txt'], 'inline' => 'maybe'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/download?dry_run=1', ['paths' => ['web/a.txt']], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-fs-download')
        ->assertJsonPath('data.steps.0.args.2', '/home/bob/web/a.txt');
    $this->fake->assertNotRan('v-add-fs-download');

    // A link that leaves the home, a missing file, a full staging folder
    $this->fake->on('v-add-fs-download')->returnsExit(ExitCode::Forbidden, 'Error: invalid path /home/bob/web/out');
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/out']], $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->fake->on('v-add-fs-download')->returnsExit(ExitCode::NotExist, "Error: /home/bob/web/gone doesn't exist");
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/gone']], $headers)->assertNotFound();
    $this->fake->on('v-add-fs-download')->returnsExit(ExitCode::Disk, 'Error: not enough space');
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/a.txt']], $headers)->assertStatus(507)->assertJsonPath('error.code', 'E_DISK');
    expect(glob($this->staging.'/downloads/*.json'))->toBe([]);
});
