<?php

namespace App\Arc;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessArcCommand implements ArcCommand
{
    use RunsJsonCommands;

    /**
     * @param  list<string>  $allowList  command names that may run (config/arc-commands.php)
     */
    public function __construct(
        private readonly array $allowList,
        private readonly string $binPath,
        private readonly string $sudo,
        private readonly string $installDir,
        private readonly string $secretDir,
        private readonly int $defaultTimeout,
        private readonly LoggerInterface $log,
    ) {}

    public function run(string $command, array $args = [], ?Options $options = null): CommandResult
    {
        $options ??= new Options;
        CommandArguments::assertCommand($command, $this->allowList);
        $masked = CommandArguments::mask($args);

        $secretFiles = [];
        try {
            $argv = [];
            foreach ($args as $arg) {
                if ($arg instanceof Secret && $arg->viaFile) {
                    $file = $this->writeSecretFile($arg);
                    $secretFiles[] = $file;
                    $argv[] = $file;
                } else {
                    $argv[] = CommandArguments::toString($command, $arg);
                }
            }

            $process = new Process(
                [$this->sudo, rtrim($this->binPath, '/').'/'.$command, ...$argv],
                '/',
                [
                    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                    'HESTIA' => $this->installDir,
                    'LANG' => 'C.UTF-8',
                ],
                null,
                $options->timeoutSeconds ?? $this->defaultTimeout,
            );

            $started = hrtime(true);
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw ArcCommandException::refused($command, 'timed out after '.$process->getTimeout().' s');
            }
            $durationMs = (hrtime(true) - $started) / 1e6;
        } finally {
            foreach ($secretFiles as $file) {
                @unlink($file);
            }
        }

        $result = new CommandResult(
            $command,
            $masked,
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput(),
            $durationMs,
        );

        $this->logResult($result);

        if (! $result->ok() && ! $options->allowFailure) {
            throw ArcCommandException::failed($result);
        }

        return $result;
    }

    private function writeSecretFile(Secret $secret): string
    {
        $file = tempnam($this->secretDir, 'arc');
        if ($file === false) {
            throw new ArcCommandException('Cannot create a secret file in '.$this->secretDir);
        }
        chmod($file, 0600);
        file_put_contents($file, $secret->reveal()."\n");

        return $file;
    }

    private function logResult(CommandResult $result): void
    {
        $context = [
            'command' => $result->command,
            'args' => $result->maskedArgs,
            'exit' => $result->exitCode,
            'ms' => round($result->durationMs, 1),
        ];

        if ($result->ok()) {
            $this->log->debug('arc command', $context);
        } else {
            $this->log->info('arc command failed', $context);
        }
    }
}
