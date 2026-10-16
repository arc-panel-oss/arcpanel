<?php

namespace App\Http\Controllers\Ports;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\PortPolicy;
use App\Services\Ports\PortActions;
use App\Services\Ports\PortService;
use App\Services\WebDomains\ApplyResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Server → Ports (E12 spec §7.3): allocations per user, live
 * sockets with the reconciliation findings, the server range and the
 * reserved ports. The rules are those of PortActions, shared with API v2
 * (E34 batch 4 spec D47).
 */
final class ServerPortController extends Controller
{
    public function __construct(
        private readonly PortService $ports,
        private readonly PortActions $actions,
    ) {}

    public function index(Principal $principal, PortPolicy $policy): View
    {
        abort_unless($policy->viewServer($principal), 403);
        $registry = $this->ports->registry();

        // Owner tree: resellers with their customers, then everyone else.
        $users = $registry->users;
        ksort($users);
        $tree = [];
        foreach ($users as $name => $row) {
            if (($row['ROLE'] ?? '') === 'reseller') {
                $tree[$name] = ['row' => $row, 'children' => array_filter($users, fn (array $child) => ($child['OWNER'] ?? '') === $name && ($child['ROLE'] ?? '') === 'user')];
            }
        }
        foreach ($users as $name => $row) {
            if (! isset($tree[$name]) && ! isset($tree[$row['OWNER'] ?? ''])) {
                $tree[$name] = ['row' => $row, 'children' => []];
            }
        }

        return view('server.ports', [
            'registry' => $registry,
            'tree' => $tree,
            'userNames' => array_keys(array_filter($users, fn (array $row) => in_array($row['ROLE'] ?? '', ['user', 'reseller', 'admin'], true))),
            'canChange' => $policy->allocate($principal),
            // The signed-in account links to Account: its own user page is refused
            'self' => $principal->effectiveUser(),
        ]);
    }

    public function allocate(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate($this->actions->allocateFormRules());
        if (! $this->actions->userExists($data['user'])) {
            throw ValidationException::withMessages(['user' => __('This user does not exist.')]);
        }
        [$range, $count] = $this->actions->rangeOrCount($data);

        return $this->done($this->ports->apply($this->ports->planAllocate(
            $data['user'], $range, $count, (bool) ($data['public_ok'] ?? false), (string) ($data['comment'] ?? ''),
        )), __('Ports have been allocated.'));
    }

    public function range(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate($this->actions->serverRangeRules());

        return $this->done($this->ports->apply($this->ports->planRange((int) $data['start'], (int) $data['end'])), __('The port range has been changed.'));
    }

    public function storeReserved(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate($this->actions->reservedRules());

        return $this->done($this->ports->apply($this->ports->planAddReserved($data['range'], (string) ($data['comment'] ?? ''))), __('Ports have been reserved.'));
    }

    public function destroyReserved(string $range, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);

        return $this->done($this->ports->apply($this->ports->planDeleteReserved($range)), __('The reservation has been deleted.'));
    }

    public function repair(Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);

        return $this->done($this->ports->apply($this->ports->planRepair()), __('The registry has been repaired.'));
    }

    /** Orphan socket: register the port to the account that listens on it. */
    public function register(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate($this->actions->registerRules());
        abort_unless($this->actions->userExists($data['user']), 404);

        return $this->done($this->ports->apply($this->ports->planRegister($data['user'], (int) $data['port'], $data['proto'])), __('The port has been registered.'));
    }

    /** Unmanaged rule: link it to the assigned port it opens. */
    public function adopt(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate($this->actions->adoptRules());
        $holder = $this->actions->adoptable((int) $data['port']);
        if ($holder === null) {
            return back()->with('error', __('Reserve the port for its user first, then link the rule.'));
        }
        abort_unless($policy->linkRule($principal, $holder->user), 403);

        return $this->done($this->ports->apply($this->ports->planAdopt($holder->user, (int) $data['port'], (string) $data['rule'])), __('The firewall rule has been linked.'));
    }

    public function closeRule(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate($this->actions->closeRuleRules());

        return $this->done($this->ports->apply($this->ports->planCloseRule((string) $data['rule'])), __('The firewall rule has been deleted.'));
    }

    private function done(ApplyResult $result, string $ok): RedirectResponse
    {
        $flash = $this->actions->flash($result, $ok);

        return $result->ok() ? back()->with($flash) : back()->withInput()->with($flash);
    }
}
