<?php

namespace App\Services\Deploy;

/**
 * The value checks of func/deploy.sh (E20 spec §4.1), so that a form or an
 * API request refuses bad input with 422 before any command runs. The
 * commands check again; these must never accept what they refuse.
 */
final class DeployFormat
{
    /** A repository URL: git@host:path, ssh://… or https://…; never file:// or a local path. */
    public static function url(string $url): bool
    {
        if (strlen($url) > 500 || preg_match('#^[A-Za-z0-9._~:/@%+=-]+$#', $url) !== 1) {
            return false;
        }
        if (preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/%+-]+$#', $url) === 1) {
            return ! str_contains($url, '..');
        }

        return preg_match('#^ssh://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~/%+-]+$#', $url) === 1
            || preg_match('#^https://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~/%+-]+$#', $url) === 1;
    }

    /** A branch or ref name, as `git check-ref-format --branch` accepts it. */
    public static function ref(string $ref): bool
    {
        if ($ref === '' || strlen($ref) > 200 || preg_match('#^[A-Za-z0-9._/@+-]+$#', $ref) !== 1 || str_starts_with($ref, '-') || $ref === '@') {
            return false;
        }
        if (str_contains($ref, '..') || str_ends_with($ref, '.') || str_contains($ref, '@{')) {
            return false;
        }
        foreach (explode('/', $ref) as $part) {
            if ($part === '' || str_starts_with($part, '.') || str_ends_with($part, '.lock')) {
                return false;
            }
        }

        return true;
    }

    /** A commit (40 hex digits) or a ref name, as v-schedule-web-domain-deploy takes it. */
    public static function revision(string $revision): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $revision) === 1
            || self::ref(str_starts_with($revision, 'refs/heads/') ? substr($revision, 11) : $revision);
    }

    /** A path inside the release: relative, without . or .. segments. */
    public static function relativePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 200
            && preg_match('#^[A-Za-z0-9._][A-Za-z0-9._/-]*$#', $path) === 1
            && ! str_contains('/'.$path.'/', '/../') && ! str_contains('/'.$path.'/', '/./');
    }

    /** WEBROOT: `.` or a folder of the release. */
    public static function webroot(string $webroot): bool
    {
        return $webroot === '.' || self::relativePath(rtrim($webroot, '/'));
    }

    /** One SHARED entry: a relative path, a trailing / for a folder. */
    public static function sharedEntry(string $entry): bool
    {
        return $entry !== '/' && self::relativePath(rtrim($entry, '/'));
    }

    /** HEALTH_PATH: empty, or a path starting with /. */
    public static function healthPath(string $path): bool
    {
        return $path === '' || (strlen($path) <= 200 && preg_match('#^/[A-Za-z0-9._~/?=&%+-]*$#', $path) === 1);
    }

    /** The repository of a trusted publisher: GitHub owner/name, GitLab group/…/project. */
    public static function repository(string $repo): bool
    {
        return strlen($repo) <= 200 && preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+){1,9}$#', $repo) === 1;
    }

    /** The ref of a trusted publisher: a branch, refs/heads/… or refs/tags/…. */
    public static function publisherRef(string $ref): bool
    {
        if (str_starts_with($ref, 'refs/')) {
            return preg_match('#^refs/(heads|tags)/(.+)$#', $ref, $m) === 1 && self::ref($m[2]);
        }

        return self::ref($ref);
    }

    /** The environment of a trusted publisher. */
    public static function environment(string $environment): bool
    {
        return strlen($environment) <= 100 && preg_match('/^[A-Za-z0-9._ -]+$/', $environment) === 1;
    }

    /** The workflow file of a trusted publisher (GitHub). */
    public static function workflow(string $workflow): bool
    {
        return self::relativePath($workflow) && (str_ends_with($workflow, '.yml') || str_ends_with($workflow, '.yaml'));
    }
}
