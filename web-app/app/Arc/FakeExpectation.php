<?php

namespace App\Arc;

use Closure;

/**
 * One expected command of ArcCommandFake and the result it returns.
 */
final class FakeExpectation
{
    private int $exitCode = 0;

    private string $stdout = '';

    private ?Closure $responder = null;

    /** @param  list<string>|Closure(list<string>): bool|null  $args */
    public function __construct(
        private readonly string $command,
        private readonly array|Closure|null $args,
    ) {}

    /** @param  list<string>  $args */
    public function matches(string $command, array $args): bool
    {
        if ($command !== $this->command) {
            return false;
        }

        return match (true) {
            $this->args === null => true,
            $this->args instanceof Closure => (bool) ($this->args)($args),
            default => $this->args === $args,
        };
    }

    public function returns(string $stdout = ''): self
    {
        $this->exitCode = 0;
        $this->stdout = $stdout;

        return $this;
    }

    /** @param  array<mixed>  $data */
    public function returnsJson(array $data): self
    {
        return $this->returns(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** Fixture file under tests/Fixtures/commands/, e.g. 'v-list-user/admin.json'. */
    public function returnsFixture(string $path): self
    {
        $file = base_path('tests/Fixtures/commands/'.$path);

        return $this->returns((string) file_get_contents($file));
    }

    public function returnsExit(ExitCode|int $code, string $stdout = ''): self
    {
        $this->exitCode = $code instanceof ExitCode ? $code->value : $code;
        $this->stdout = $stdout;

        return $this;
    }

    /**
     * Compute the result from the arguments.
     *
     * @param  Closure(list<string>): (CommandResult|array{0: int, 1: string})  $responder
     */
    public function using(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    /**
     * @param  list<string>  $maskedArgs
     * @param  list<string>  $args
     */
    public function result(string $command, array $maskedArgs, array $args): CommandResult
    {
        if ($this->responder !== null) {
            $answer = ($this->responder)($args);
            if ($answer instanceof CommandResult) {
                return $answer;
            }
            [$exit, $stdout] = $answer;

            return new CommandResult($command, $maskedArgs, $exit, $stdout);
        }

        return new CommandResult($command, $maskedArgs, $this->exitCode, $this->stdout);
    }
}
