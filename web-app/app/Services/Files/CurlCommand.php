<?php

namespace App\Services\Files;

use InvalidArgumentException;

/**
 * Reads one pasted curl command into the fields of a FetchRequest (upload
 * from URL, docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
 * §6.2). The command is never run: it is split the way bash would split it
 * and only the options of the table in §6.2 are accepted, so a request can
 * only ever be a GET of one URL with headers and credentials.
 */
final class CurlCommand
{
    /** Options that take a value and what they set */
    private const VALUE_OPTIONS = [
        '-H' => 'header', '--header' => 'header',
        '-u' => 'user', '--user' => 'user',
        '--oauth2-bearer' => 'bearer',
        '-A' => 'agent', '--user-agent' => 'agent',
        '-e' => 'referer', '--referer' => 'referer',
        '-b' => 'cookie', '--cookie' => 'cookie',
        '-o' => 'output', '--output' => 'output',
        '-X' => 'method', '--request' => 'method',
        '--url' => 'url',
    ];

    /** Options without a value that change nothing: the server follows redirects and names the file */
    private const IGNORED = [
        '-L', '--location', '-O', '--remote-name', '-J', '--remote-header-name', '--compressed', '-s', '--silent',
        '-S', '--show-error', '-f', '--fail', '-g', '--globoff', '-#', '--progress-bar', '--http1.1', '--http2',
    ];

    /** Short flags that may be combined (-sSL) */
    private const SHORT_FLAGS = 'LOJsSfg#';

    /**
     * @return array{url: string, headers: list<array{name: string, value: string}>, auth: array{type: string, username?: string, password?: string, token?: string}, name: string}
     *
     * @throws InvalidArgumentException with a message for the user
     */
    public static function parse(string $command): array
    {
        $words = self::split($command);
        if ($words === [] || $words[0] !== 'curl') {
            throw new InvalidArgumentException(__('Paste a command that starts with curl.'));
        }
        $url = '';
        $headers = [];
        $auth = ['type' => 'none'];
        $name = '';
        $set = function (string $what, string $value) use (&$url, &$headers, &$auth, &$name): void {
            switch ($what) {
                case 'url':
                    if ($url !== '') {
                        throw new InvalidArgumentException(__('Paste a command with one URL.'));
                    }
                    $url = $value;
                    break;
                case 'header':
                    if (! str_contains($value, ':')) {
                        throw new InvalidArgumentException(sprintf(__('The header "%s" has no value.'), $value));
                    }
                    [$n, $v] = explode(':', $value, 2);
                    // curl would store the compressed bytes (§6.2)
                    if (strtolower(trim($n)) !== 'accept-encoding') {
                        $headers[] = ['name' => trim($n), 'value' => trim($v)];
                    }
                    break;
                case 'user':
                    [$user, $password] = array_pad(explode(':', $value, 2), 2, '');
                    $auth = ['type' => 'basic', 'username' => $user, 'password' => $password];
                    break;
                case 'bearer':
                    $auth = ['type' => 'bearer', 'token' => $value];
                    break;
                case 'agent':
                    $headers[] = ['name' => 'User-Agent', 'value' => $value];
                    break;
                case 'referer':
                    $headers[] = ['name' => 'Referer', 'value' => $value];
                    break;
                case 'cookie':
                    if (! str_contains($value, '=')) {
                        throw new InvalidArgumentException(__('Cookies from a file are not supported; paste the cookie values.'));
                    }
                    $headers[] = ['name' => 'Cookie', 'value' => $value];
                    break;
                case 'output':
                    $name = basename(str_replace('\\', '/', $value));
                    break;
                case 'method':
                    if (strtoupper($value) !== 'GET') {
                        throw new InvalidArgumentException(sprintf(__('Only GET requests are supported, not %s.'), $value));
                    }
                    break;
            }
        };

        for ($i = 1, $n = count($words); $i < $n; $i++) {
            $word = $words[$i];
            if ($word === '' || $word[0] !== '-' || $word === '-') {
                $set('url', $word);

                continue;
            }
            // --option=value
            if (str_starts_with($word, '--') && str_contains($word, '=')) {
                [$option, $value] = explode('=', $word, 2);
                if (! isset(self::VALUE_OPTIONS[$option])) {
                    throw self::refused($option);
                }
                $set(self::VALUE_OPTIONS[$option], $value);

                continue;
            }
            if (isset(self::VALUE_OPTIONS[$word])) {
                if ($i + 1 >= $n) {
                    throw new InvalidArgumentException(sprintf(__('The option %s needs a value.'), $word));
                }
                $set(self::VALUE_OPTIONS[$word], $words[++$i]);

                continue;
            }
            if (in_array($word, self::IGNORED, true)) {
                continue;
            }
            // -HValue and -XGET: a short option with its value attached
            if (! str_starts_with($word, '--') && strlen($word) > 2 && isset(self::VALUE_OPTIONS[substr($word, 0, 2)])
                && strspn(substr($word, 1), self::SHORT_FLAGS) !== strlen($word) - 1) {
                $set(self::VALUE_OPTIONS[substr($word, 0, 2)], substr($word, 2));

                continue;
            }
            // Combined short flags (-sSL)
            if (! str_starts_with($word, '--') && strspn(substr($word, 1), self::SHORT_FLAGS) === strlen($word) - 1) {
                continue;
            }
            throw self::refused($word);
        }
        if ($url === '') {
            throw new InvalidArgumentException(__('The command has no URL.'));
        }

        return ['url' => $url, 'headers' => $headers, 'auth' => $auth, 'name' => $name];
    }

    /**
     * Words of a shell command line: quotes, ANSI-C quotes, backslash
     * escapes and line continuations; anything that would make the shell
     * run something else is refused.
     *
     * @return list<string>
     */
    public static function split(string $command): array
    {
        // Line continuations of bash (\) and of cmd.exe (^)
        $command = preg_replace('/(?:\\\\|\^)\r?\n/', ' ', $command);
        $words = [];
        $word = null;
        $len = strlen($command);
        for ($i = 0; $i < $len; $i++) {
            $c = $command[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                if ($word !== null) {
                    $words[] = $word;
                    $word = null;
                }

                continue;
            }
            $word ??= '';
            if ($c === "'") {
                $end = strpos($command, "'", $i + 1);
                if ($end === false) {
                    throw new InvalidArgumentException(__('A quote is not closed.'));
                }
                $word .= substr($command, $i + 1, $end - $i - 1);
                $i = $end;
            } elseif ($c === '$' && ($command[$i + 1] ?? '') === "'") {
                [$text, $i] = self::ansiC($command, $i + 2);
                $word .= $text;
            } elseif ($c === '"') {
                for ($i++; $i < $len && $command[$i] !== '"'; $i++) {
                    $d = $command[$i];
                    if ($d === '\\' && in_array($command[$i + 1] ?? '', ['"', '\\', '$', '`'], true)) {
                        $word .= $command[++$i];
                    } elseif ($d === '$' || $d === '`') {
                        throw self::shell();
                    } else {
                        $word .= $d;
                    }
                }
                if ($i >= $len) {
                    throw new InvalidArgumentException(__('A quote is not closed.'));
                }
            } elseif ($c === '\\') {
                $word .= $command[++$i] ?? '';
            } elseif (str_contains('$`|;&<>(){}', $c)) {
                throw self::shell();
            } else {
                $word .= $c;
            }
        }
        if ($word !== null) {
            $words[] = $word;
        }

        return $words;
    }

    /**
     * The text of a $'…' quote from OFFSET, and the index of its closing quote.
     *
     * @return array{0: string, 1: int}
     */
    private static function ansiC(string $command, int $offset): array
    {
        $text = '';
        $len = strlen($command);
        for ($i = $offset; $i < $len; $i++) {
            $c = $command[$i];
            if ($c === "'") {
                return [$text, $i];
            }
            if ($c !== '\\') {
                $text .= $c;

                continue;
            }
            $next = $command[++$i] ?? '';
            switch ($next) {
                case 'n': $text .= "\n";
                    break;
                case 't': $text .= "\t";
                    break;
                case 'r': $text .= "\r";
                    break;
                case 'x':
                    preg_match('/^[0-9a-fA-F]{1,2}/', substr($command, $i + 1), $m);
                    $text .= chr(hexdec($m[0] ?? '0'));
                    $i += strlen($m[0] ?? '');
                    break;
                case 'u':
                    preg_match('/^[0-9a-fA-F]{1,4}/', substr($command, $i + 1), $m);
                    $text .= mb_chr(hexdec($m[0] ?? '0'), 'UTF-8');
                    $i += strlen($m[0] ?? '');
                    break;
                default:
                    $text .= $next;
            }
        }

        throw new InvalidArgumentException(__('A quote is not closed.'));
    }

    private static function refused(string $option): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(__('The option %s is not supported.'), $option));
    }

    private static function shell(): InvalidArgumentException
    {
        return new InvalidArgumentException(__('Variables, pipes and other shell syntax are not supported; paste a single curl command.'));
    }
}
