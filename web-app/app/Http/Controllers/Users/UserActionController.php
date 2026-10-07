<?php

namespace App\Http\Controllers\Users;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\UserPolicy;
use App\Services\Users\UserActions;
use App\Services\Users\UserService;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Suspend, unsuspend and delete users, one or in bulk (web/bulk/user). A
 * reseller with customers is not deleted (E11 spec §5.4); every item is
 * checked before any command runs.
 */
final class UserActionController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly TenancyDirectory $tenancy,
        private readonly UserActions $actions,
    ) {}

    public function suspend(string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $user, $principal, $policy);
    }

    public function unsuspend(string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $user, $principal, $policy);
    }

    public function destroy(string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        return $this->single('delete', $user, $principal, $policy);
    }

    public function bulk(Request $request, Principal $principal, UserPolicy $policy, OwnerScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:suspend,unsuspend,delete,suspend-tree'],
            'items' => ['required', 'array', 'max:1000'],
            'items.*' => ['string', 'regex:/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/'],
        ]);
        $action = (string) $data['action'];
        $items = array_values(array_unique($data['items']));

        // Every item must be visible and allowed before anything runs.
        foreach ($items as $user) {
            $scope->assertVisible($user);
            abort_unless($this->allowed($action, $user, $principal, $policy), 403);
        }

        if ($action === 'suspend-tree') {
            abort_unless($principal->actingRole() === Role::Admin, 403);
            $tree = [];
            foreach ($items as $user) {
                $tree = [...$tree, $user, ...$this->tenancy->customersOf($user)];
            }
            $items = array_values(array_unique($tree));
            $action = 'suspend';
        }

        $refused = $action === 'delete' ? $this->resellersWithCustomers($items) : [];
        $owners = array_unique(array_map(fn (string $user) => $this->tenancy->ownerOf($user), $items));
        $result = $this->users->bulk($action, array_values(array_diff($items, array_keys($refused))));
        $result['failed'] += $refused;
        foreach ($owners as $owner) {
            $this->users->updateCounters($owner);
        }

        $message = sprintf(__('%d done, %d failed'), count($result['done']), count($result['failed']));
        if ($result['failed'] !== []) {
            $details = [];
            foreach ($result['failed'] as $user => $error) {
                $details[] = $user.': '.$error;
            }

            return back()->with('error', $message)->with('error_detail', implode("\n", $details));
        }

        return back()->with('ok', $message);
    }

    private function single(string $action, string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        abort_unless($this->allowed($action, $user, $principal, $policy), 403);
        if ($action === 'delete' && ($refused = $this->resellersWithCustomers([$user])) !== []) {
            return back()->with('error', $refused[$user]);
        }

        $owner = $this->tenancy->ownerOf($user);
        $result = $this->users->bulk($action, [$user]);
        $this->users->updateCounters($owner);
        if ($result['failed'] !== []) {
            return back()->with('error', sprintf(__('%s failed'), $user))->with('error_detail', $result['failed'][$user]);
        }

        $messages = [
            'suspend' => __('User has been suspended.'),
            'unsuspend' => __('User has been unsuspended.'),
            'delete' => __('User has been deleted.'),
        ];

        return $action === 'delete'
            ? redirect()->route('users.index')->with('ok', $messages[$action])
            : back()->with('ok', $messages[$action]);
    }

    private function allowed(string $action, string $user, Principal $principal, UserPolicy $policy): bool
    {
        return match ($action) {
            'delete' => $policy->delete($principal, $user),
            default => $policy->suspend($principal, $user),
        };
    }

    /**
     * @param  list<string>  $users
     * @return array<string, string> reseller => reason
     */
    private function resellersWithCustomers(array $users): array
    {
        $refused = [];
        foreach ($users as $user) {
            if (($reason = $this->actions->deleteRefusal($user)) !== null) {
                $refused[$user] = $reason;
            }
        }

        return $refused;
    }
}
