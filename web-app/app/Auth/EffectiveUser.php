<?php

namespace App\Auth;

/**
 * Owner of the object a route works on: the {user} route parameter after
 * the `effective-user` middleware checked it against OwnerScope.
 */
final class EffectiveUser
{
    public function __construct(public readonly string $name) {}
}
