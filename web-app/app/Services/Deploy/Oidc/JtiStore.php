<?php

namespace App\Services\Deploy\Oidc;

/**
 * The used `jti` values of upload tokens, each kept until its `exp`
 * (E20 spec §6.3 step 5).
 */
interface JtiStore
{
    /** Records the jti; false when it was already recorded and has not expired. */
    public function remember(string $jti, int $exp): bool;
}
