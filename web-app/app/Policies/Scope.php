<?php

namespace App\Policies;

/**
 * How far a role's capability reaches (docs/design/03 §6).
 */
enum Scope: string
{
    /** Any user on the server. */
    case All = 'all';

    /** Users in the principal's tree (resellers, E11). */
    case Tree = 'tree';

    /** Only the effective user. */
    case Self = 'self';
}
