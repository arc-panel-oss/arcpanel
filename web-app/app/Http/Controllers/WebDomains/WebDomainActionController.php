<?php

namespace App\Http\Controllers\WebDomains;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\WebDomainPolicy;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Single and bulk actions on web domains (web/bulk/web, web/suspend/web …):
 * every command with RESTART=no and one round of restarts at the end.
 */
final class WebDomainActionController extends Controller
{
    public function __construct(private readonly WebDomainService $web) {}

    public function suspend(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $user, $domain, $policy->suspend($principal, $user));
    }

    public function unsuspend(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $user, $domain, $policy->suspend($principal, $user));
    }

    public function rebuild(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        return $this->single('rebuild', $user, $domain, $policy->rebuild($principal, $user));
    }

    public function purgeCache(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        return $this->single('purge', $user, $domain, $policy->update($principal, $user) && $this->web->canPurgeCache());
    }

    public function destroy(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        return $this->single('delete', $user, $domain, $policy->delete($principal, $user));
    }

    /**
     * POST /app/web/domains/bulk {action, items[]} with items "user/domain".
     * Every item is checked (scope, then policy) before any command runs.
     */
    public function bulk(Request $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(WebDomainService::BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'max:300', 'regex:/^[[:alnum:]][-.\w]*\/[^\/\s]+$/u'],
        ]);
        $action = $data['action'];

        $items = [];
        foreach (array_unique($data['items']) as $item) {
            [$user, $domain] = explode('/', $item, 2);
            $scope->assertVisible($user);
            abort_unless($this->allowed($action, $principal, $policy, $user), 403);
            $items[] = ['user' => $user, 'domain' => $domain];
        }

        $result = $this->web->bulk($action, $items);
        $done = count($result['done']);
        $failed = count($result['failed']);

        $redirect = redirect()->route('web.domains.index', array_filter(['owner' => $request->query('owner')]));
        if ($failed > 0) {
            $details = implode('; ', array_map(fn ($domain, $error) => "{$domain}: {$error}", array_keys($result['failed']), $result['failed']));

            return $redirect->with('error', sprintf(__('%d done, %d failed'), $done, $failed))->with('error_detail', $details);
        }

        return $redirect->with('ok', sprintf(__('%d done, %d failed'), $done, 0));
    }

    private function single(string $action, string $user, string $domain, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $this->web->find($user, $domain);

        $result = $this->web->bulk($action, [['user' => $user, 'domain' => $domain]]);
        $back = $action === 'delete'
            ? redirect()->route('web.domains.index')
            : redirect()->route('web.domains.edit', ['user' => $user, 'domain' => $domain]);

        if ($result['failed'] !== []) {
            return $back->with('error', reset($result['failed']));
        }

        return $back->with('ok', match ($action) {
            'suspend' => sprintf(__('Domain %s has been suspended.'), $domain),
            'unsuspend' => sprintf(__('Domain %s has been unsuspended.'), $domain),
            'rebuild' => sprintf(__('Domain %s has been rebuilt.'), $domain),
            'purge' => __('Nginx cache has been purged.'),
            default => sprintf(__('Domain %s has been deleted.'), $domain),
        });
    }

    private function allowed(string $action, Principal $principal, WebDomainPolicy $policy, string $user): bool
    {
        return match ($action) {
            'delete' => $policy->delete($principal, $user),
            'rebuild' => $policy->rebuild($principal, $user),
            'purge' => $policy->update($principal, $user) && $this->web->canPurgeCache(),
            default => $policy->suspend($principal, $user),
        };
    }
}
