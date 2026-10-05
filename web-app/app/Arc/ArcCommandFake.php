<?php

namespace App\Arc;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * Test double for ArcCommand.
 *
 *     $fake = ArcCommandFake::install();
 *     $fake->on('v-list-user', ['admin', 'json'])->returnsJson([...]);
 *     $fake->on('v-add-web-domain')->returnsExit(ExitCode::Exists, 'Error: exists');
 *     $fake->assertRan('v-add-web-domain', fn (array $args) => $args[1] === 'example.com');
 *
 * Any command without a matching expectation fails the test, so code cannot
 * silently start calling something new. Secrets are recorded as revealed
 * values so tests can assert on them; file secrets are not written to disk.
 */
final class ArcCommandFake implements ArcCommand
{
    use RunsJsonCommands;

    /** @var list<FakeExpectation> */
    private array $expectations = [];

    /** @var list<array{command: string, args: list<string>}> */
    private array $calls = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(ArcCommand::class, $fake);

        return $fake;
    }

    /**
     * Expect a command. With $args, only calls with exactly these arguments
     * match; a closure receives the argument list and returns bool.
     * Later expectations win over earlier ones.
     *
     * @param  list<string>|Closure(list<string>): bool|null  $args
     */
    public function on(string $command, array|Closure|null $args = null): FakeExpectation
    {
        $expectation = new FakeExpectation($command, $args);
        array_unshift($this->expectations, $expectation);

        return $expectation;
    }

    public function run(string $command, array $args = [], ?Options $options = null): CommandResult
    {
        $options ??= new Options;
        CommandArguments::assertCommand($command, [$command]);
        // File secrets reach the command as a file, like ProcessArcCommand
        // writes them, so line breaks are allowed in them.
        $plain = array_map(fn ($arg) => $arg instanceof Secret && $arg->viaFile ? $arg->reveal() : CommandArguments::toString($command, $arg), array_values($args));
        $this->calls[] = ['command' => $command, 'args' => $plain];

        foreach ($this->expectations as $expectation) {
            if ($expectation->matches($command, $plain)) {
                $result = $expectation->result($command, CommandArguments::mask($args), $plain);
                if (! $result->ok() && ! $options->allowFailure) {
                    throw ArcCommandException::failed($result);
                }

                return $result;
            }
        }

        Assert::fail(sprintf(
            "Unexpected command: %s %s\nAdd \$fake->on('%s', ...) to the test.",
            $command,
            implode(' ', array_map('escapeshellarg', $plain)),
            $command,
        ));
    }

    /** @return list<array{command: string, args: list<string>}> */
    public function calls(?string $command = null): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call) => $command === null || $call['command'] === $command,
        ));
    }

    /** @param  (Closure(list<string>): bool)|null  $where */
    public function assertRan(string $command, ?Closure $where = null, ?int $times = null): void
    {
        $matching = array_filter(
            $this->calls($command),
            fn (array $call) => $where === null || $where($call['args']),
        );

        if ($times === null) {
            Assert::assertNotEmpty($matching, "Expected {$command} to run, it did not.");
        } else {
            Assert::assertCount($times, $matching, "Expected {$command} to run {$times} time(s).");
        }
    }

    public function assertNotRan(string $command): void
    {
        Assert::assertEmpty($this->calls($command), "Expected {$command} not to run, it did.");
    }
}
