<?php

namespace App\Arc;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessArcCommand implements ArcCommand
{
    use RunsJsonCommands {
        json as private decodeJson;
    }

    /** Read-only commands, which never clear the v-list-user memo */
    private const READ_ONLY = '/^v-(list|get|check|search)-/';

    /** Seconds a memoized v-list-user answer stays valid */
    private const MEMO_TTL = 2.0;

    /** @var array<string, array{0: float, 1: array<mixed>}> v-list-user USER json of this request, by user */
    private array $userMemo = [];

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

    /**
     * `v-list-user USER json` runs on almost every page, often from several
     * services in one request (the principal, the page's service, a policy).
     * It is answered once and reused for two seconds at most; any command
     * that changes something clears the memo (docs/design/02: "v-list-user
     * per request").
     */
    public function json(string $command, array $args = [], ?Options $options = null): array
    {
        if ($command !== 'v-list-user' || count($args) !== 1 || ! is_string($args[0])) {
            return $this->decodeJson($command, $args, $options);
        }
        $now = hrtime(true) / 1e9;
        [$at, $data] = $this->userMemo[$args[0]] ?? [0.0, null];
        if ($data !== null && $now - $at < self::MEMO_TTL) {
            return $data;
        }
        $data = $this->decodeJson($command, $args, $options);
        if ($data !== []) {
            $this->userMemo[$args[0]] = [$now, $data];
        }

        return $data;
    }

    public function run(string $command, array $args = [], ?Options $options = null): CommandResult
    {
        $options ??= new Options;
        if (preg_match(self::READ_ONLY, $command) !== 1) {
            $this->userMemo = [];
        }
        CommandArguments::assertCommand($command, $this->allowList);
        $masked = CommandArguments::mask($args);

        $secretFiles = [];
        try {
            $argv = [];
            foreach ($args as $arg) {
                if ($arg instanceof Secret && $arg->isDirectory()) {
                    $dir = $this->writeSecretDirectory($arg);
                    $secretFiles[] = $dir;
                    $argv[] = $dir;
                } elseif ($arg instanceof Secret && $arg->viaFile) {
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
                    'ARCPANEL' => $this->installDir,
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
                if (is_dir($file)) {
                    array_map('unlink', glob($file.'/*') ?: []);
                    @rmdir($file);
                } else {
                    @unlink($file);
                }
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

    /** A 0700 directory of 0600 files (SSL_DIR of the certificate commands). */
    private function writeSecretDirectory(Secret $secret): string
    {
        $dir = rtrim($this->secretDir, '/').'/arc'.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700)) {
            throw new ArcCommandException('Cannot create a secret directory in '.$this->secretDir);
        }
        foreach ($secret->files() as $name => $content) {
            $file = $dir.'/'.$name;
            touch($file);
            chmod($file, 0600);
            file_put_contents($file, $content);
        }

        return $dir;
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
