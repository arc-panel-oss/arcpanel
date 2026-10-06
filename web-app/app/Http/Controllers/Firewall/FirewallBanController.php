<?php

namespace App\Http\Controllers\Firewall;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The ban list of the firewall extension (web/list|add|delete|bulk/firewall/
 * banlist), E7 batch 2 spec §6. Items are "CHAIN:IP" (spec §12.3).
 */
final class FirewallBanController extends Controller
{
    public function __construct(private readonly FirewallService $firewall) {}

    public function index(Principal $principal, FirewallPolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);

        return view('server.firewall.bans', [
            'bans' => $this->firewall->bans(),
            'canManage' => $policy->manage($principal),
        ]);
    }

    public function create(Principal $principal, FirewallPolicy $policy): View
    {
        abort_unless($policy->manage($principal), 403);

        return view('server.firewall.ban-create', ['chains' => FirewallService::CHAINS]);
    }

    public function store(Request $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $labels = ['chain' => __('Banlist'), 'ip' => __('IP Address')];
        $data = $request->validate([
            'chain' => ['required', Rule::in(FirewallService::CHAINS)],
            'ip' => ['required', 'string', 'max:64', 'not_regex:/[\r\n\x00]/'],
        ], collect($labels)->mapWithKeys(fn (string $label, string $field) => [$field.'.required' => sprintf(__('Field "%s" can not be blank.'), $label)])->all(), $labels);

        $result = $this->firewall->createBan(trim($data['ip']), $data['chain']);
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.firewall.bans')->with('ok', __('IP address has been banned successfully.'));
    }

    /** DELETE /app/server/firewall/bans {item: "CHAIN:IP"} */
    public function destroy(Request $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $bans = $this->checked([(string) $request->input('item')]);

        $result = $this->firewall->deleteBans($bans);
        $back = redirect()->route('server.firewall.bans');

        return $result->ok() ? $back->with('ok', __('IP address has been unbanned.')) : $back->with('error', $result->firstError());
    }

    /** POST /app/server/firewall/bans/bulk {action: delete, items[]} */
    public function bulk(Request $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'max:80'],
        ]);

        return $this->firewall->deleteBans($this->checked($data['items']))->flash(redirect()->route('server.firewall.bans'));
    }

    /**
     * @param  list<string>  $items
     * @return list<array{0: string, 1: string}> [chain, ip] of listed bans
     */
    private function checked(array $items): array
    {
        $known = array_map(fn ($ban) => $ban->item(), $this->firewall->bans());
        $bans = [];
        foreach (array_values(array_unique($items)) as $item) {
            $ban = FirewallService::parseBanItem($item);
            abort_if($ban === null, 422);
            abort_unless(in_array($item, $known, true), 404);
            $bans[] = $ban;
        }

        return $bans;
    }
}
