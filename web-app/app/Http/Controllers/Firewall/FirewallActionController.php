<?php

namespace App\Http\Controllers\Firewall;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallService;
use App\Services\Support\BulkResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Single and bulk actions on firewall rules (web/move|suspend|unsuspend|
 * delete/firewall, web/bulk/firewall). Rules of the port registry are never
 * suspended or deleted here (spec D9); moving them is allowed.
 */
final class FirewallActionController extends Controller
{
    public function __construct(private readonly FirewallService $firewall) {}

    public function move(string $rule, string $direction, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $this->firewall->rule($rule);

        $result = $this->firewall->move($rule, $direction);
        $back = redirect()->route('server.firewall');

        return $result->ok() ? $back : $back->with('error', FirewallController::message($result));
    }

    public function suspend(string $rule, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $rule, $principal, $policy);
    }

    public function unsuspend(string $rule, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $rule, $principal, $policy);
    }

    public function destroy(string $rule, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        return $this->single('delete', $rule, $principal, $policy);
    }

    /** POST /app/server/firewall/bulk {action, items[]} with rule positions. */
    public function bulk(Request $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(FirewallService::BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'regex:/^\d{1,9}$/'],
        ]);

        $rules = $this->firewall->rules();
        $ids = [];
        $skipped = [];
        foreach (array_values(array_unique($data['items'])) as $id) {
            abort_unless(isset($rules[$id]), 404);
            if ($policy->change($principal, $rules[$id]->comment)) {
                $ids[] = $id;
            } else {
                $skipped['#'.$id] = __('Managed by Ports');
            }
        }

        $result = $this->firewall->bulk($data['action'], $ids);

        return (new BulkResult($result->done, $result->failed + $skipped))->flash(redirect()->route('server.firewall'));
    }

    private function single(string $action, string $rule, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $record = $this->firewall->rule($rule);
        abort_unless($policy->change($principal, $record->comment), 403);

        $result = $this->firewall->bulk($action, [$rule]);
        $back = redirect()->route('server.firewall');
        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        return $back->with('ok', match ($action) {
            'suspend' => __('Rule has been suspended.'),
            'unsuspend' => __('Rule has been unsuspended.'),
            default => __('Rule has been deleted.'),
        });
    }
}
