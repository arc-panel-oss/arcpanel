<?php

namespace App\Services\Support;

/**
 * The owners a list page shows: all visible owners, or the one of the
 * ?owner= filter.
 */
final class OwnerSelection
{
    /** @param  list<string>  $owners */
    public function __construct(
        public readonly array $owners,
        /** the ?owner= filter, '' for none */
        public readonly string $owner,
        /** administrators see every user of the server */
        public readonly bool $everyUser,
    ) {}

    /** @return list<string> owners whose objects are listed */
    public function users(): array
    {
        return $this->owner !== '' ? [$this->owner] : $this->owners;
    }

    /** The owner column: administrators and resellers with customers. */
    public function showOwner(): bool
    {
        return count($this->owners) > 1 || $this->everyUser;
    }
}
