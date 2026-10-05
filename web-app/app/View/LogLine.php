<?php

namespace App\View;

/**
 * Severity class of a log line for x-ap-log; the same rules as lineLevel()
 * in resources/js/alpine/log.js.
 */
final class LogLine
{
    public static function level(string $line): string
    {
        if (preg_match('/\b(error|crit|alert|emerg|fatal)\b/i', $line) === 1 || preg_match('/"\s5\d\d\s/', $line) === 1) {
            return 'is-error';
        }
        if (preg_match('/\b(warn|warning|notice)\b/i', $line) === 1 || preg_match('/"\s4\d\d\s/', $line) === 1) {
            return 'is-warn';
        }

        return '';
    }
}
