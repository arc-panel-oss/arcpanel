<?php

namespace App\Http\Controllers\Firewall;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallActions;
use App\Services\Firewall\FirewallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * IPset lists of the firewall (web/list|add|delete|bulk/firewall/ipset),
 * E7 batch 2 spec §6.
 */
final class FirewallIpsetController extends Controller
{
    private const NAME = 'regex:/^[\w.-]{1,255}$/';

    public function __construct(
        private readonly FirewallService $firewall,
        private readonly FirewallActions $actions,
    ) {}

    public function index(Principal $principal, FirewallPolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);

        return view('server.firewall.ipsets', [
            'lists' => $this->firewall->ipsets(),
            'canManage' => $policy->manage($principal),
        ]);
    }

    public function create(Principal $principal, FirewallPolicy $policy): View
    {
        abort_unless($policy->manage($principal), 403);

        return view('server.firewall.ipset-create');
    }

    public function store(Request $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $labels = ['name' => __('IP List Name'), 'source' => __('Data Source'), 'version' => __('IP Version'), 'autoupdate' => __('Auto Update')];
        $data = $request->validate([
            'name' => ['required', 'string', self::NAME],
            'source' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'version' => ['required', 'in:v4,v6'],
            'autoupdate' => ['required', 'in:yes,no'],
        ], collect($labels)->mapWithKeys(fn (string $label, string $field) => [$field.'.required' => sprintf(__('Field "%s" can not be blank.'), $label)])->all(), $labels);

        $result = $this->firewall->createIpset($data['name'], trim($data['source']), $data['version'], $data['autoupdate']);
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.firewall.ipsets')->with('ok', __('IP list has been created successfully.'));
    }

    public function destroy(string $name, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $this->actions->assertIpsetsExist([$name]);

        $result = $this->firewall->deleteIpsets([$name]);
        $back = redirect()->route('server.firewall.ipsets');

        return $result->ok() ? $back->with('ok', __('IP list has been deleted.')) : $back->with('error', $result->firstError());
    }

    /** POST /app/server/firewall/ipsets/bulk {action: delete, items[]} */
    public function bulk(Request $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', self::NAME],
        ]);
        $names = array_values(array_unique($data['items']));
        $this->actions->assertIpsetsExist($names);

        return $this->firewall->deleteIpsets($names)->flash(redirect()->route('server.firewall.ipsets'));
    }
}
