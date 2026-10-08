<?php

namespace App\Http\Controllers\Firewall;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Firewall\FirewallRuleRequest;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallActions;
use App\Services\Firewall\FirewallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Firewall rules: list, add and edit (web/list/firewall, web/add/firewall,
 * web/edit/firewall), E7 batch 2 spec §6.
 */
final class FirewallController extends Controller
{
    public function __construct(private readonly FirewallService $firewall) {}

    public function index(Principal $principal, FirewallPolicy $policy, SysConfig $config): View
    {
        abort_unless($policy->viewAny($principal), 403);

        return view('server.firewall.index', [
            'rules' => array_values($this->firewall->rules()),
            'canManage' => $policy->manage($principal),
            'banlist' => $config->get('FIREWALL_EXTENSION') !== '',
            'bulkActions' => [
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d rules?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d rules?')],
            ],
        ]);
    }

    public function create(Principal $principal, FirewallPolicy $policy): View
    {
        abort_unless($policy->manage($principal), 403);

        return view('server.firewall.form', [
            'rule' => null,
            'ipsets' => $this->firewall->ruleIpsets(),
            'canChange' => true,
        ]);
    }

    public function store(FirewallRuleRequest $request, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);

        $rule = $request->rule();
        $result = $this->firewall->create($rule['action'], $rule['ip'], $rule['port'], $rule['protocol'], $rule['comment']);
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.firewall')->with('ok', __('Rule has been created successfully.'));
    }

    public function edit(string $rule, Principal $principal, FirewallPolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);
        $record = $this->firewall->rule($rule);

        return view('server.firewall.form', [
            'rule' => $record,
            'ipsets' => $this->firewall->ruleIpsets(),
            'canChange' => $policy->change($principal, $record->comment),
        ]);
    }

    public function update(FirewallRuleRequest $request, string $rule, Principal $principal, FirewallPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal), 403);
        $record = $this->firewall->rule($rule);
        abort_unless(FirewallActions::changeable($record), 403);

        $values = $request->rule();
        $result = $this->firewall->update($rule, $values['action'], $values['ip'], $values['port'], $values['protocol'], $values['comment']);
        $back = redirect()->route('server.firewall.edit', ['rule' => $rule]);
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->errorMessage());
        }

        return $back->with('ok', __('Changes have been saved.'));
    }
}
