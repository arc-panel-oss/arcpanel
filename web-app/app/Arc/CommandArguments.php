<?php

namespace App\Arc;

use Stringable;

/**
 * Argument rules shared by the real runner and the test fake, so a test fails
 * on the same input the server would refuse.
 */
final class CommandArguments
{
    /** @param  list<string>  $allowList */
    public static function assertCommand(string $command, array $allowList): void
    {
        if (preg_match('/^v-[a-z0-9-]+$/', $command) !== 1) {
            throw ArcCommandException::refused($command, 'invalid command name');
        }
        if (! in_array($command, $allowList, true)) {
            throw ArcCommandException::refused($command, 'not in the allow-list');
        }
    }

    public static function toString(string $command, mixed $arg): string
    {
        $value = match (true) {
            $arg instanceof Secret => $arg->reveal(),
            is_string($arg) => $arg,
            is_int($arg) => (string) $arg,
            $arg instanceof Stringable => (string) $arg,
            default => throw ArcCommandException::refused($command, 'argument of type '.get_debug_type($arg)),
        };

        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw ArcCommandException::refused($command, 'argument contains a control character');
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $args
     * @return list<string>
     */
    public static function mask(array $args): array
    {
        return array_map(
            fn (mixed $arg): string => match (true) {
                $arg instanceof Secret => '******',
                is_string($arg), is_int($arg), $arg instanceof Stringable => (string) $arg,
                default => get_debug_type($arg),
            },
            array_values($args),
        );
    }
}
