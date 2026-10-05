<?php

namespace App\Http\Controllers\Ports;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\PortPolicy;
use App\Services\Ports\PortService;
use App\Services\WebDomains\ApplyResult;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Server → Ports (E12 spec §7.3): allocations per user, live
 * sockets with the reconciliation findings, the server range and the
 * reserved ports.
 */
final class ServerPortController extends Controller
{
    private const RANGE = '/^\d{1,5}(-\d{1,5})?$/';

    private const COMMENT = '/^[[:alnum:]][[:alnum:][:space:]._-]{0,64}[[:alnum:]]$/';

    public function __construct(
        private readonly PortService $ports,
        private readonly TenancyDirectory $tenancy,
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
        ]);
    }

    public function allocate(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate([
            'user' => ['required', 'string', 'regex:/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/'],
            'mode' => ['required', 'in:range,count'],
            'range' => ['nullable', 'required_if:mode,range', 'string', 'regex:'.self::RANGE],
            'count' => ['nullable', 'required_if:mode,count', 'integer', 'min:1', 'max:10000'],
            'public_ok' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ]);
        if (! $this->tenancy->exists($data['user'])) {
            throw ValidationException::withMessages(['user' => __('This user does not exist.')]);
        }
        $range = $data['mode'] === 'range' ? (string) $data['range'] : null;
        $count = $data['mode'] === 'count' ? (int) $data['count'] : null;

        return $this->done($this->ports->apply($this->ports->planAllocate(
            $data['user'], $range, $count, (bool) ($data['public_ok'] ?? false), (string) ($data['comment'] ?? ''),
        )), __('Ports have been allocated.'));
    }

    public function range(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate([
            'start' => ['required', 'integer', 'min:1024', 'max:65534'],
            'end' => ['required', 'integer', 'gt:start', 'max:65535'],
        ]);

        return $this->done($this->ports->apply($this->ports->planRange((int) $data['start'], (int) $data['end'])), __('The port range has been changed.'));
    }

    public function storeReserved(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate([
            'range' => ['required', 'string', 'regex:'.self::RANGE],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ]);

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
        $data = $request->validate([
            'user' => ['required', 'string', 'regex:/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'proto' => ['required', 'in:tcp,udp'],
        ]);
        abort_unless($this->tenancy->exists($data['user']), 404);

        return $this->done($this->ports->apply($this->ports->planRegister($data['user'], (int) $data['port'], $data['proto'])), __('The port has been registered.'));
    }

    /** Unmanaged rule: link it to the assigned port it opens. */
    public function adopt(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate([
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'rule' => ['required', 'integer', 'min:1'],
        ]);
        $holder = $this->ports->registry()->holderOf((int) $data['port']);
        if ($holder === null || ! $holder->assigned() || $holder->isRange()) {
            return back()->with('error', __('Reserve the port for its user first, then link the rule.'));
        }

        return $this->done($this->ports->apply($this->ports->planAdopt($holder->user, (int) $data['port'], (string) $data['rule'])), __('The firewall rule has been linked.'));
    }

    public function closeRule(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validate(['rule' => ['required', 'integer', 'min:1']]);

        return $this->done($this->ports->apply($this->ports->planCloseRule((string) $data['rule'])), __('The firewall rule has been deleted.'));
    }

    private function done(ApplyResult $result, string $ok): RedirectResponse
    {
        return $result->ok()
            ? back()->with('ok', $ok)
            : back()->withInput()->with('error', sprintf(__('%s failed'), $result->failed?->label))->with('error_detail', $result->error);
    }
}
