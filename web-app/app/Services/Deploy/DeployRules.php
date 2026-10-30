<?php

namespace App\Services\Deploy;

use Closure;

/**
 * Validation of Git Deploy input, the checks of the CLI (E20 spec §4.1,
 * func/deploy.sh) before any command runs, for the Deploy tab and REST API v2.
 */
final class DeployRules
{
    /** git@host:path, ssh://… or https://…; never file:// or a local path. */
    public static function urlValid(string $url, bool $httpsOnly = false): bool
    {
        if (strlen($url) > 500 || preg_match('#^[A-Za-z0-9._~:/@%+=-]+$#', $url) !== 1) {
            return false;
        }
        $https = preg_match('#^https://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._~/%+-]+$#', $url) === 1;
        if ($httpsOnly) {
            return $https;
        }

        return $https
            || (preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/%+-]+$#', $url) === 1 && ! str_contains($url, '..'))
            || preg_match('#^ssh://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._~/%+-]+$#', $url) === 1;
    }

    /** A branch name as git check-ref-format --branch accepts it (the usual subset). */
    public static function branchValid(string $branch): bool
    {
        return $branch !== '' && strlen($branch) <= 200
            && preg_match('#^[A-Za-z0-9._/@+-]+$#', $branch) === 1
            && ! str_starts_with($branch, '-') && ! str_starts_with($branch, '/') && ! str_ends_with($branch, '/')
            && ! str_ends_with($branch, '.lock') && ! str_ends_with($branch, '.')
            && ! str_contains($branch, '..') && ! str_contains($branch, '//') && ! str_contains($branch, '@{')
            && ! preg_match('#(^|/)\.#', $branch);
    }

    /** A relative path inside the release: no "..", no leading "/". */
    public static function relativeValid(string $path): bool
    {
        return $path !== '' && strlen($path) <= 200
            && preg_match('#^[A-Za-z0-9._][A-Za-z0-9._/-]*$#', $path) === 1
            && ! str_contains('/'.$path.'/', '/../') && ! str_contains('/'.$path.'/', '/./');
    }

    public static function webrootValid(string $webroot): bool
    {
        return $webroot === '.' || self::relativeValid(rtrim($webroot, '/'));
    }

    /** Shared paths separated by spaces, commas or lines, one space between. */
    public static function normalizeShared(string $shared): string
    {
        return implode(' ', preg_split('/[\s,]+/', trim($shared), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public static function sharedValid(string $shared): bool
    {
        $entries = preg_split('/\s+/', self::normalizeShared($shared), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($entries) > 20) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '/' || ! self::relativeValid(rtrim($entry, '/'))) {
                return false;
            }
        }

        return true;
    }

    public static function healthValid(string $path): bool
    {
        return $path === '' || (strlen($path) <= 200 && preg_match('#^/[A-Za-z0-9._~/?=&%+-]*$#', $path) === 1);
    }

    /**
     * @param  string  $auth  key or token: a token needs an HTTPS address
     * @return array<string, mixed>
     */
    public static function pull(string $auth = 'key'): array
    {
        return [
            'url' => ['required', 'string', 'max:500', $auth === 'token'
                ? self::check(fn (string $v) => self::urlValid($v, true), __('A token needs an HTTPS address of the repository.'))
                : self::check(fn (string $v) => self::urlValid($v), __('Enter an SSH (git@host:path or ssh://) or HTTPS address of the repository.'))],
            'branch' => ['required', 'string', 'max:200', self::check(fn (string $v) => self::branchValid($v), __('This is not a valid branch name.'))],
            'auth' => ['required', 'in:key,token'],
            'token' => ['nullable', 'required_if:auth,token', 'string', 'max:500', 'not_regex:/[
]/'],
            'preset' => ['required', 'in:'.implode(',', DeployService::PRESETS)],
            'webroot' => ['required', 'string', 'max:200', self::check(fn (string $v) => self::webrootValid($v), __('Enter . or a folder of the release.'))],
        ];
    }

    /**
     * @param  array<string, string>  $issuers  allowed issuers => labels
     * @return array<string, mixed>
     */
    public static function push(array $issuers): array
    {
        return ['webroot' => ['required', 'string', 'max:200', self::check(fn (string $v) => self::webrootValid($v), __('Enter . or a folder of the release.'))]]
            + self::publisher($issuers);
    }

    /**
     * @param  array<string, string>  $issuers
     * @return array<string, mixed>
     */
    public static function publisher(array $issuers): array
    {
        return [
            'issuer' => ['required', 'string', 'in:'.implode(',', array_keys($issuers))],
            'repo' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+){1,9}$#'],
            'ref' => ['nullable', 'string', 'max:200', 'required_without:environment', self::check(
                fn (string $v) => self::branchValid(preg_replace('#^refs/(heads|tags)/#', '', $v) ?? $v),
                __('This is not a valid branch name.'),
            )],
            'environment' => ['nullable', 'string', 'max:100', 'required_without:ref', 'regex:/^[A-Za-z0-9._ -]+$/'],
            'workflow' => ['nullable', 'string', 'max:200', 'regex:#^[A-Za-z0-9._][A-Za-z0-9._/-]*\.ya?ml$#', 'not_regex:#(^|/)\.\.(/|$)#'],
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(bool $pull): array
    {
        $rules = [
            'keep' => ['required', 'integer', 'min:1', 'max:20'],
            'shared' => ['nullable', 'string', 'max:2000', self::check(fn (string $v) => self::sharedValid($v), __('Enter up to 20 relative paths inside the release, a trailing / for a folder.'))],
            'health_path' => ['nullable', 'string', 'max:200', self::check(fn (string $v) => self::healthValid($v), __('Enter a path that starts with /, or leave it empty.'))],
            'webroot' => ['required', 'string', 'max:200', self::check(fn (string $v) => self::webrootValid($v), __('Enter . or a folder of the release.'))],
        ];
        if ($pull) {
            $rules += [
                'branch' => ['required', 'string', 'max:200', self::check(fn (string $v) => self::branchValid($v), __('This is not a valid branch name.'))],
                'preset' => ['required', 'in:'.implode(',', DeployService::PRESETS)],
                'migrate' => ['nullable', 'boolean'],
            ];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public static function token(string $url): array
    {
        return ['token' => ['required', 'string', 'max:500', 'not_regex:/[\r\n]/', function (string $attribute, mixed $value, Closure $fail) use ($url) {
            if (! self::urlValid($url, true)) {
                $fail(__('A token needs an HTTPS address of the repository.'));
            }
        }]];
    }

    /** A closure rule that fails with MESSAGE when TEST is false for a string value. */
    private static function check(Closure $test, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($test, $message) {
            if (! is_string($value) || ! $test($value)) {
                $fail($message);
            }
        };
    }
}
