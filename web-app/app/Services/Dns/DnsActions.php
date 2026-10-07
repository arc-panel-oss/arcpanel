<?php

namespace App\Services\Dns;

use App\Tenancy\TenancyDirectory;
use Illuminate\Validation\ValidationException;

/**
 * Checks the DNS pages and API v2 share (E34 batch 2 spec D25): a template
 * name the server has, and whether the owner exists.
 */
final class DnsActions
{
    public function __construct(
        private readonly DnsService $dns,
        private readonly TenancyDirectory $directory,
    ) {}

    /** The owner exists; 404 otherwise (an administrator may name any user). */
    public function ownerExists(string $owner): bool
    {
        return array_key_exists($owner, $this->directory->all());
    }

    /**
     * A template other than the current one must be allowed and exist.
     * An empty template, or a caller who may not choose one, is left to the plan.
     *
     * @throws ValidationException
     */
    public function assertTemplate(bool $allowed, string $template): void
    {
        if ($template === '' || ! $allowed) {
            return;
        }
        if (! in_array($template, $this->dns->templates(), true)) {
            throw ValidationException::withMessages(['template' => __('Please select a valid template.')]);
        }
    }

    /** The next record id: one past the highest id the zone has (D21). */
    public function nextRecordId(string $user, string $domain): string
    {
        $highest = 0;
        foreach ($this->dns->records($user, $domain) as $record) {
            if (ctype_digit($record->id)) {
                $highest = max($highest, (int) $record->id);
            }
        }

        return (string) ($highest + 1);
    }
}
