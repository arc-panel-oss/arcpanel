<?php

namespace App\Auth;

/**
 * ROLE of a user (user.conf) and userContext of a session.
 */
enum Role: string
{
    case Admin = 'admin';
    case Reseller = 'reseller';
    case User = 'user';
    case DnsCluster = 'dns-cluster';

    /** Unknown or empty values are treated as the least privileged role. */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::User;
    }
}
