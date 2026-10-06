<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * The web terminal page (E7 batch 2 spec D11, §5.2). The terminal server
 * opens a shell for the account of the legacy session, which keeps the
 * reseller's own account while it acts as a customer (E11): the page is
 * hidden then.
 */
final class TerminalPolicy
{
    public function __construct(private readonly SysConfig $config) {}

    public function use(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'terminal.use')
            && $this->config->is('WEB_TERMINAL', 'true')
            && $principal->loginShell !== 'nologin'
            && ! $principal->isReadOnly()
            && ! ($principal->loginRole === Role::Reseller && $principal->isImpersonating());
    }
}
