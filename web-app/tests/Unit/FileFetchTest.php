<?php

use App\Services\Files\CurlCommand;
use App\Services\Files\FetchRequest;
use App\Services\Files\FileFetch;
use Illuminate\Validation\ValidationException;

// Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
// §6.2, §6.3): the curl parser, the request and the records.

function curlRefusal(string $command): string
{
    try {
        CurlCommand::parse($command);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return '';
}

/** @return array<string, string> */
function fetchErrors(array $data): array
{
    try {
        FetchRequest::from($data);
    } catch (ValidationException $e) {
        return array_map(fn ($m) => $m[0], $e->errors());
    }

    return [];
}

it('reads a command copied from Chrome', function () {
    $command = <<<'SH'
        curl 'https://downloads.example.com/v2/release.zip?sig=abc&exp=1' \
          -H 'accept: application/octet-stream' \
          -H 'accept-language: en-US,en;q=0.9' \
          -b 'session=xyz; theme=dark' \
          -H 'user-agent: Mozilla/5.0 (X11)' \
          --compressed
        SH;

    expect(CurlCommand::parse($command))->toBe([
        'url' => 'https://downloads.example.com/v2/release.zip?sig=abc&exp=1',
        'headers' => [
            ['name' => 'accept', 'value' => 'application/octet-stream'],
            ['name' => 'accept-language', 'value' => 'en-US,en;q=0.9'],
            ['name' => 'Cookie', 'value' => 'session=xyz; theme=dark'],
            ['name' => 'user-agent', 'value' => 'Mozilla/5.0 (X11)'],
        ],
        'auth' => ['type' => 'none'],
        'name' => '',
    ]);
});

it('reads a command copied from Firefox and drops Accept-Encoding', function () {
    $command = "curl 'https://example.com/a.tar.gz' -H 'User-Agent: Mozilla/5.0' -H 'Accept-Encoding: gzip, deflate, br, zstd' -H 'Authorization: Bearer t0k'";

    $parsed = CurlCommand::parse($command);
    expect($parsed['headers'])->toBe([
        ['name' => 'User-Agent', 'value' => 'Mozilla/5.0'],
        ['name' => 'Authorization', 'value' => 'Bearer t0k'],
    ]);
});

it('reads credentials, agent, referer, output and combined flags', function () {
    $parsed = CurlCommand::parse('curl -sSL -u "alice:p@ss word" -A agent/1 -e https://ref.example/ -o out/file.bin --url=https://a.example/x -X GET');
    expect($parsed['url'])->toBe('https://a.example/x')
        ->and($parsed['auth'])->toBe(['type' => 'basic', 'username' => 'alice', 'password' => 'p@ss word'])
        ->and($parsed['headers'])->toBe([['name' => 'User-Agent', 'value' => 'agent/1'], ['name' => 'Referer', 'value' => 'https://ref.example/']])
        ->and($parsed['name'])->toBe('file.bin');

    expect(CurlCommand::parse('curl --oauth2-bearer abc https://a.example/')['auth'])->toBe(['type' => 'bearer', 'token' => 'abc'])
        ->and(CurlCommand::parse('curl -HX-Test:1 -XGET https://a.example/')['headers'])->toBe([['name' => 'X-Test', 'value' => '1']]);
});

it('splits quotes, ANSI-C quotes, escapes and continuations like bash', function () {
    expect(CurlCommand::split("curl 'a b' \"c \\\"d\\\"\" e\\ f \$'g\\nh\\x41\\u00e9' \\\n -L"))
        ->toBe(['curl', 'a b', 'c "d"', 'e f', "g\nhAé", '-L'])
        ->and(CurlCommand::split("curl ^\r\n -L"))->toBe(['curl', '-L']);
});

it('refuses other options, methods, several URLs and shell syntax, naming the option', function () {
    expect(curlRefusal('curl -d a=1 https://a.example/'))->toContain('-d')
        ->and(curlRefusal('curl --data-raw x https://a.example/'))->toContain('--data-raw')
        ->and(curlRefusal('curl -k https://a.example/'))->toContain('-k')
        ->and(curlRefusal('curl -K /etc/passwd https://a.example/'))->toContain('-K')
        ->and(curlRefusal('curl --proxy http://p https://a.example/'))->toContain('--proxy')
        ->and(curlRefusal('curl -X POST https://a.example/'))->toContain('POST')
        ->and(curlRefusal('curl https://a.example/ https://b.example/'))->toContain('one URL')
        ->and(curlRefusal('curl -b cookies.txt https://a.example/'))->not->toBe('')
        ->and(curlRefusal('curl https://a.example/$(id)'))->toContain('shell')
        ->and(curlRefusal('curl "https://a.example/$HOME"'))->toContain('shell')
        ->and(curlRefusal('curl https://a.example/ | sh'))->toContain('shell')
        ->and(curlRefusal('curl https://a.example/; rm x'))->toContain('shell')
        ->and(curlRefusal("curl 'https://a.example/"))->toContain('quote')
        ->and(curlRefusal('wget https://a.example/'))->toContain('curl')
        ->and(curlRefusal('curl -L'))->toContain('no URL')
        ->and(curlRefusal('curl -H'))->toContain('-H');
});

it('validates a request and writes the request file', function () {
    $request = FetchRequest::from([
        'url' => ' https://a.example/f.zip ',
        'headers' => [['name' => 'X-Key', 'value' => 'v'], ['name' => '', 'value' => '']],
        'auth' => ['type' => 'basic', 'username' => 'alice', 'password' => 'p w'],
        'name' => 'f.zip',
    ]);

    expect($request->toRequestFile())->toBe("url https://a.example/f.zip\nname f.zip\nheader X-Key: v\nbasic alice:p w\n")
        ->and($request->summary())->toBe(['url' => 'https://a.example/f.zip', 'header_names' => ['X-Key'], 'auth' => 'basic', 'name' => 'f.zip'])
        ->and(FetchRequest::from(['url' => 'https://a.example/', 'auth' => ['type' => 'bearer', 'token' => 't']])->toRequestFile())
        ->toBe("url https://a.example/\nbearer t\n");
});

it('refuses bad URLs, headers, credentials and names with field errors', function () {
    expect(fetchErrors(['url' => '']))->toHaveKey('url')
        ->and(fetchErrors(['url' => 'ftp://a.example/']))->toHaveKey('url')
        ->and(fetchErrors(['url' => 'https://u:p@a.example/']))->toHaveKey('url')
        ->and(fetchErrors(['url' => 'https://a.example/ x']))->toHaveKey('url')
        ->and(fetchErrors(['url' => 'https://a.example/', 'headers' => [['name' => 'Bad Name', 'value' => 'x']]]))->toHaveKey('headers.0.name')
        ->and(fetchErrors(['url' => 'https://a.example/', 'headers' => [['name' => 'Host', 'value' => 'b']]]))->toHaveKey('headers.0.name')
        ->and(fetchErrors(['url' => 'https://a.example/', 'headers' => [['name' => 'X', 'value' => "a\r\nB: c"]]]))->toHaveKey('headers.0.value')
        ->and(fetchErrors(['url' => 'https://a.example/', 'headers' => array_fill(0, 51, ['name' => 'X', 'value' => 'y'])]))->toHaveKey('headers')
        ->and(fetchErrors(['url' => 'https://a.example/', 'auth' => ['type' => 'digest']]))->toHaveKey('auth.type')
        ->and(fetchErrors(['url' => 'https://a.example/', 'auth' => ['type' => 'basic', 'username' => 'a:b']]))->toHaveKey('auth.username')
        ->and(fetchErrors(['url' => 'https://a.example/', 'auth' => ['type' => 'bearer', 'token' => '']]))->toHaveKey('auth.token')
        ->and(fetchErrors(['url' => 'https://a.example/', 'name' => '../x']))->toHaveKey('name');
});

it('shows URLs without user information and query', function () {
    expect(FetchRequest::display('https://u:p@a.example:8443/x/y.zip?sig=1#f'))->toBe('https://a.example:8443/x/y.zip?…')
        ->and(FetchRequest::display('http://a.example'))->toBe('http://a.example/');
});

it('reads a record with its folder relative to the home', function () {
    $fetch = FileFetch::from([
        'ID' => '17611234560042', 'URL' => 'https://a.example/f.zip', 'DIR' => '/home/bob/web/a.example/public_html', 'NAME' => 'f.zip',
        'STATUS' => 'running', 'BYTES' => '50', 'TOTAL' => '200', 'ERROR' => '', 'HTTP' => '200',
        'CREATED' => '2026-10-22 10:00:00', 'START' => '2026-10-22 10:00:01', 'END' => '',
    ], '/home/bob');

    expect($fetch->toArray())->toMatchArray(['path' => 'web/a.example/public_html', 'percent' => 25, 'total' => 200, 'http' => 200])
        ->and($fetch->active())->toBeTrue()
        ->and(FileFetch::from(['STATUS' => 'running', 'TOTAL' => '', 'DIR' => '/home/bob'], '/home/bob')->percent())->toBeNull()
        ->and(FileFetch::from(['STATUS' => 'done', 'DIR' => '/home/bob'], '/home/bob')->percent())->toBe(100)
        ->and(FileFetch::from(['STATUS' => 'odd', 'DIR' => '/etc'], '/home/bob')->toArray())->toMatchArray(['status' => 'failed', 'path' => '']);
});
