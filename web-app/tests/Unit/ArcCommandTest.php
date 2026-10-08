<?php

use App\Arc\ArcCommandException;
use App\Arc\ArcCommandFake;
use App\Arc\CommandArguments;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Arc\ProcessArcCommand;
use App\Arc\Secret;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\AbstractLogger;

/** Logger that keeps every line, to check what reaches the log. */
function memoryLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = $level.' '.$message.' '.json_encode($context);
        }
    };
}

/** A ProcessArcCommand that runs scripts from a temporary bin directory without sudo. */
function localRunner(array $scripts, ?AbstractLogger $log = null): array
{
    $bin = sys_get_temp_dir().'/arc-bin-'.bin2hex(random_bytes(4));
    mkdir($bin);
    foreach ($scripts as $name => $body) {
        file_put_contents("{$bin}/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$bin}/{$name}", 0755);
    }
    $runner = new ProcessArcCommand(
        allowList: array_keys($scripts),
        binPath: $bin,
        sudo: '/usr/bin/env',
        installDir: '/usr/local/arcpanel',
        secretDir: sys_get_temp_dir(),
        defaultTimeout: 10,
        log: $log ?? memoryLogger(),
    );

    return [$runner, $bin];
}

it('refuses command names that are not v-* scripts', function (string $name) {
    CommandArguments::assertCommand($name, [$name]);
})->with(['ls', 'v-list-user; id', 'v-List-user', '../bin/v-list-user', 'v-list user'])
    ->throws(ArcCommandException::class, 'invalid command name');

it('refuses commands missing from the allow-list', function () {
    CommandArguments::assertCommand('v-delete-user', ['v-list-user']);
})->throws(ArcCommandException::class, 'not in the allow-list');

it('refuses arguments with control characters', function (string $value) {
    CommandArguments::toString('v-list-user', $value);
})->with(["a\nb", "a\0b", "a\rb"])->throws(ArcCommandException::class, 'control character');

it('refuses arrays and other non-scalar arguments', function () {
    CommandArguments::toString('v-list-user', ['admin']);
})->throws(ArcCommandException::class, 'argument of type array');

it('masks secrets in the logged arguments', function () {
    expect(CommandArguments::mask(['admin', Secret::inline('hunter2'), 42]))
        ->toBe(['admin', '******', '42']);
    expect((string) Secret::file('hunter2'))->toBe('******');
});

it('maps exit codes to HTTP status and error names', function () {
    expect(ExitCode::Exists->httpStatus())->toBe(409)
        ->and(ExitCode::NotExist->httpStatus())->toBe(404)
        ->and(ExitCode::Limit->httpStatus())->toBe(429)
        ->and(ExitCode::Restart->httpStatus())->toBe(500)
        ->and(ExitCode::Forbidden->errorCode())->toBe('E_FORBIDEN');
});

it('runs a command with each argument passed as one argv item', function () {
    [$runner] = localRunner(['v-echo-args' => 'for a in "$@"; do printf "[%s]\n" "$a"; done']);

    $result = $runner->run('v-echo-args', ['two words', "it's", '', '$(id)', 7]);

    expect($result->lines())->toBe(['[two words]', "[it's]", '[]', '[$(id)]', '[7]']);
});

it('passes a file secret as a 0600 file under the secret directory and removes it', function () {
    [$runner] = localRunner(['v-read-secret' => 'stat -c %a "$1"; head -n1 "$1"; echo "$1"']);

    $result = $runner->run('v-read-secret', [Secret::file('s3cr3t')]);
    [$mode, $content, $path] = $result->lines();

    expect($mode)->toBe('600')
        ->and($content)->toBe('s3cr3t')
        ->and(str_starts_with($path, sys_get_temp_dir()))->toBeTrue()
        ->and(file_exists($path))->toBeFalse();
});

it('throws on a non-zero exit, or returns the result when failure is allowed', function () {
    [$runner] = localRunner(['v-fail' => 'echo "Error: user bob doesn\'t exist"; exit 3']);

    expect(fn () => $runner->run('v-fail'))
        ->toThrow(ArcCommandException::class, "Error: user bob doesn't exist");

    $result = $runner->run('v-fail', [], Options::allowFailure());
    expect($result->ok())->toBeFalse()
        ->and($result->exit())->toBe(ExitCode::NotExist);
});

it('answers v-list-user once until a command changes something', function () {
    $count = sys_get_temp_dir().'/arc-count-'.bin2hex(random_bytes(4));
    [$runner] = localRunner([
        'v-list-user' => "echo x >> {$count}; printf '{\"%s\": {\"N\": \"%s\"}}' \"\$1\" \"\$(wc -l < {$count})\"",
        'v-list-users' => 'echo "{}"',
        'v-change-user-shell' => 'true',
    ]);

    expect($runner->json('v-list-user', ['bob'])['bob']['N'])->toBe('1')
        ->and($runner->json('v-list-user', ['bob'])['bob']['N'])->toBe('1');
    $runner->json('v-list-users');
    expect($runner->json('v-list-user', ['bob'])['bob']['N'])->toBe('1')
        ->and($runner->json('v-list-user', ['ann'])['ann']['N'])->toBe('2');

    $runner->run('v-change-user-shell', ['bob', 'bash']);
    expect($runner->json('v-list-user', ['bob'])['bob']['N'])->toBe('3');
    @unlink($count);
});

it('never writes a secret to the log', function () {
    $log = memoryLogger();
    [$runner] = localRunner(['v-fail' => 'exit 9'], $log);

    $runner->run('v-fail', ['admin', Secret::inline('hunter2'), Secret::file('hunter3')], Options::allowFailure());

    expect(implode("\n", $log->lines))
        ->toContain('v-fail')
        ->not->toContain('hunter2')
        ->not->toContain('hunter3');
});

it('decodes JSON output and appends the json format argument', function () {
    [$runner] = localRunner(['v-list-thing' => 'echo "{\"args\": \"$*\"}"']);

    expect($runner->json('v-list-thing', ['bob']))->toBe(['args' => 'bob json']);
});

it('fails the test on a command the fake was not told about', function () {
    $fake = new ArcCommandFake;
    $fake->run('v-delete-user', ['bob']);
})->throws(AssertionFailedError::class, 'Unexpected command: v-delete-user');

it('lets the fake match arguments and record calls', function () {
    $fake = new ArcCommandFake;
    $fake->on('v-list-user', ['bob', 'json'])->returnsJson(['bob' => ['ROLE' => 'user']]);
    $fake->on('v-add-web-domain')->returnsExit(ExitCode::Exists, 'Error: exists');

    expect($fake->json('v-list-user', ['bob']))->toBe(['bob' => ['ROLE' => 'user']]);
    expect(fn () => $fake->run('v-add-web-domain', ['bob', 'example.com']))
        ->toThrow(ArcCommandException::class);

    $fake->assertRan('v-add-web-domain', fn (array $args) => $args[1] === 'example.com');
    $fake->assertNotRan('v-delete-web-domain');
});

it('reports the command message to the user', function () {
    $result = new CommandResult('v-add-user', ['bob'], 4, "Error: user bob exists\n");

    expect(ArcCommandException::failed($result)->userMessage())->toBe('Error: user bob exists')
        ->and(ArcCommandException::failed($result)->httpStatus())->toBe(409);
});
