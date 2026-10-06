<?php

namespace App\Http\Controllers\Ips;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\IpPolicy;
use App\Services\Ips\IpService;
use App\Services\UserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * IP addresses of the server (web/list|add|edit|bulk|delete/ip), E7 batch 2
 * spec §7.
 */
final class IpController extends Controller
{
    public function __construct(private readonly IpService $ips) {}

    public function index(Principal $principal, IpPolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);

        return view('server.ips.index', [
            'ips' => $this->ips->all(),
            'canManage' => $policy->manage($principal),
        ]);
    }

    public function create(Principal $principal, IpPolicy $policy, UserDirectory $directory): View
    {
        abort_unless($policy->manage($principal), 403);

        return view('server.ips.form', [
            'ip' => null,
            'interfaces' => $this->ips->interfaces(),
            'owners' => $directory->names(),
            'canManage' => true,
        ]);
    }

    public function store(Request $request, Principal $principal, IpPolicy $policy, UserDirectory $directory): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $labels = ['ip' => __('IP Address'), 'netmask' => __('Netmask'), 'interface' => __('Interface'), 'owner' => __('Assigned User')];
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:45', 'regex:/^[0-9A-Fa-f:.]{2,45}$/'],
            'netmask' => ['required', 'string', 'max:45', 'regex:/^[0-9A-Fa-f:.\/]{1,45}$/'],
            'interface' => ['required', 'string', Rule::in($this->ips->interfaces())],
            'owner' => ['required', 'string', Rule::in($directory->names())],
            ...self::optionalRules(),
        ], self::blankMessages($labels), $labels);

        $result = $this->ips->create($data['ip'], $data['netmask'], $data['interface'], $data['owner'],
            $request->boolean('shared'), (string) ($data['name'] ?? ''), (string) ($data['nat'] ?? ''));
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.ips')
            ->with('ok', sprintf(__('IP address %s has been created successfully.'), $data['ip']))
            ->with('ok_link', ['url' => route('server.ips.edit', ['ip' => $data['ip']]), 'label' => __('Edit IP Address')]);
    }

    public function edit(string $ip, Principal $principal, IpPolicy $policy, UserDirectory $directory): View
    {
        abort_unless($policy->viewAny($principal), 403);

        return view('server.ips.form', [
            'ip' => $this->ips->find($ip),
            'interfaces' => [],
            'owners' => $directory->names(),
            'canManage' => $policy->manage($principal),
        ]);
    }

    public function update(Request $request, string $ip, Principal $principal, IpPolicy $policy, UserDirectory $directory): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $current = $this->ips->find($ip);
        $data = $request->validate([
            'owner' => ['nullable', 'string', Rule::in($directory->names())],
            ...self::optionalRules(),
        ]);

        $steps = $this->ips->planUpdate($current, $request->boolean('shared'), (string) ($data['owner'] ?? ''),
            trim((string) ($data['name'] ?? '')), trim((string) ($data['nat'] ?? '')));
        $result = $this->ips->apply($steps);
        $back = redirect()->route('server.ips.edit', ['ip' => $ip]);
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->error);
        }

        return $back->with('ok', __('Changes have been saved.'));
    }

    public function destroy(string $ip, Principal $principal, IpPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $this->ips->find($ip);

        $result = $this->ips->delete([$ip]);
        $back = redirect()->route('server.ips');

        return $result->ok() ? $back->with('ok', sprintf(__('IP address %s has been deleted.'), $ip)) : $back->with('error', $result->firstError());
    }

    /** POST /app/server/ips/bulk {action: delete, items[]} */
    public function bulk(Request $request, Principal $principal, IpPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'regex:/^[0-9A-Fa-f:.]{2,45}$/'],
        ]);
        $known = array_map(fn ($ip) => $ip->ip, $this->ips->all());
        $items = array_values(array_unique($data['items']));
        foreach ($items as $ip) {
            abort_unless(in_array($ip, $known, true), 404);
        }

        return $this->ips->delete($items)->flash(redirect()->route('server.ips'));
    }

    /** POST /app/server/ips/refresh: `v-update-sys-ip`, without a selection (spec §12.4). */
    public function refresh(Principal $principal, IpPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);

        $result = $this->ips->refresh();
        $back = redirect()->route('server.ips');

        return $result->ok() ? $back->with('ok', __('IP addresses have been refreshed.')) : $back->with('error', $result->errorMessage());
    }

    /** @return array<string, list<string>> */
    private static function optionalRules(): array
    {
        return [
            'shared' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]*$/'],
            'nat' => ['nullable', 'string', 'max:45', 'regex:/^[0-9A-Fa-f:.]*$/'],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private static function blankMessages(array $labels): array
    {
        $messages = [];
        foreach ($labels as $field => $label) {
            $messages[$field.'.required'] = sprintf(__('Field "%s" can not be blank.'), $label);
        }

        return $messages;
    }
}
