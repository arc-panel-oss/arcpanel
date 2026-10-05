<?php

namespace App\Services\Support;

use Illuminate\Http\RedirectResponse;

/**
 * Outcome of a bulk action: the labels of the items that ran, and the
 * command message per failed item.
 */
final class BulkResult
{
    /**
     * @param  list<string>  $done
     * @param  array<string, string>  $failed  label => message
     */
    public function __construct(
        public readonly array $done = [],
        public readonly array $failed = [],
    ) {}

    public function ok(): bool
    {
        return $this->failed === [];
    }

    /** First failure message, for single actions. */
    public function firstError(): string
    {
        return (string) (reset($this->failed) ?: '');
    }

    /** The "n done, m failed" toast of every list page. */
    public function flash(RedirectResponse $redirect): RedirectResponse
    {
        $done = count($this->done);
        $failed = count($this->failed);
        if ($failed > 0) {
            $details = implode('; ', array_map(fn ($label, $error) => "{$label}: {$error}", array_keys($this->failed), $this->failed));

            return $redirect->with('error', sprintf(__('%d done, %d failed'), $done, $failed))->with('error_detail', $details);
        }

        return $redirect->with('ok', sprintf(__('%d done, %d failed'), $done, 0));
    }

    /** @return array{done: list<string>, failed: array<string, string>} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'failed' => $this->failed];
    }
}
