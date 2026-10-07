<?php

namespace App\Services\Databases;

use App\Tenancy\TenancyDirectory;
use Illuminate\Validation\ValidationException;

/**
 * Checks the database pages and API v2 share (E34 batch 2 spec D25): a type
 * and host the server has, and a short name that does not already carry the
 * owner prefix.
 */
final class DatabaseActions
{
    public function __construct(
        private readonly DatabaseService $databases,
        private readonly TenancyDirectory $directory,
    ) {}

    /** The owner exists; 404 otherwise (an administrator may name any user). */
    public function ownerExists(string $owner): bool
    {
        return array_key_exists($owner, $this->directory->all());
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws ValidationException
     */
    public function assertAllowed(string $field, string $value, array $allowed, string $message): void
    {
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * A short name that already starts with the owner prefix would be prefixed twice.
     *
     * @throws ValidationException
     */
    public function assertShortName(string $field, string $owner, string $short): void
    {
        if (str_starts_with(strtolower($short), strtolower($owner).'_')) {
            throw ValidationException::withMessages([$field => __('Do not include the owner prefix.')]);
        }
    }

    /** The name `v-add-database` stores: the owner prefix, lower case for PostgreSQL. */
    public function fullName(string $owner, string $short, string $type): string
    {
        $name = $owner.'_'.$short;

        return $type === 'pgsql' ? strtolower($name) : $name;
    }
}
