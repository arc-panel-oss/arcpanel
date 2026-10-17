<?php

namespace App\Auth;

use App\Arc\SysConfig;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * POLICY_SYSTEM_PROTECTED_ADMIN (E39 W4 spec §5): only the ROOT_USER itself
 * changes its account and its objects. The login account decides, so
 * impersonation changes nothing; reads stay allowed. Bound per request: a
 * refusal of a request in which the guard blocked answers 404 instead of
 * 403 under POLICY_SYSTEM_HIDE_ADMIN, so the root administrator's objects
 * cannot be probed.
 */
final class ProtectedAdmin
{
    private bool $blocked = false;

    public function __construct(private readonly SysConfig $config) {}

    /** Whether a change of $principal to $owner or its objects is refused. */
    public function blocks(Principal $principal, string $owner): bool
    {
        if (! $this->applies($principal, $owner)) {
            return false;
        }
        $this->blocked = true;

        return true;
    }

    /** The rule alone, for code that hides rather than refuses (search). */
    public function applies(Principal $principal, string $owner): bool
    {
        return $owner !== ''
            && $this->config->is('POLICY_SYSTEM_PROTECTED_ADMIN')
            && $owner === $this->config->get('ROOT_USER')
            && $principal->loginUser !== $owner;
    }

    /** A 403 of this request becomes a 404 when the guard blocked and HIDE_ADMIN is on. */
    public function hide(Throwable $e): Throwable
    {
        $refused = $e instanceof AuthorizationException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403);

        return $refused && $this->blocked && $this->config->is('POLICY_SYSTEM_HIDE_ADMIN')
            ? new NotFoundHttpException(previous: $e)
            : $e;
    }
}
