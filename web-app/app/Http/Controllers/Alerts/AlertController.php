<?php

namespace App\Http\Controllers\Alerts;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\AlertPolicy;
use App\Services\Alerts\AlertIndex;
use App\Services\Alerts\AlertIndexUnavailable;
use App\Services\Alerts\AlertStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Alerts pages (E16 spec §7): the tabs Open, Rules, Channels and History
 * of an account's scope (`/alerts/{user}`) and of the server
 * (`/server/alerts`, where the route gives `system` as the user). Channel
 * secrets are write-only: they go to the commands in a Secret file and are
 * never shown, flashed or kept in the session.
 */
final class AlertController extends Controller
{
    public const PAGE_SIZE = 50;

    public const PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30, 'all' => null];

    public function __construct(private readonly AlertStore $alerts) {}

    /** /alerts: the scope of the effective account. */
    public function entry(Principal $principal): RedirectResponse
    {
        return redirect()->route('alerts.show', ['user' => $principal->effectiveUser()]);
    }

    public function show(Request $request, string $user, Principal $principal, AlertPolicy $policy, AlertIndex $index): View
    {
        abort_unless($policy->view($principal, $user), $user === 'system' ? 403 : 404);
        $filters = $request->validate([
            'rule' => ['nullable', 'string', 'max:64'],
            'severity' => ['nullable', Rule::in(AlertStore::SEVERITIES)],
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'tab' => ['nullable', Rule::in(['open', 'rules', 'channels', 'history'])],
        ]);
        $period = $filters['period'] ?? '30d';
        $page = max(1, (int) $request->query('page', '1'));
        $history = ['items' => [], 'total' => 0];
        $historyError = null;
        try {
            $index->refresh();
            $query = $index->query()->orderByDesc('fired_at')->orderByDesc('id');
            if ($user === 'system') {
                $query->where('scope', 'system');
            } else {
                $query->where('scope', 'user')->where('user', $user);
            }
            if (($filters['rule'] ?? '') !== '') {
                $query->where('rule', $filters['rule']);
            }
            if (($filters['severity'] ?? '') !== '') {
                $query->where('severity', $filters['severity']);
            }
            if (self::PERIODS[$period] !== null) {
                $query->where('fired_at', '>=', date('Y-m-d H:i:s', time() - self::PERIODS[$period] * 86400));
            }
            $total = (clone $query)->count();
            $history = ['items' => $query->limit(self::PAGE_SIZE)->offset(($page - 1) * self::PAGE_SIZE)->get()->all(), 'total' => $total];
        } catch (AlertIndexUnavailable) {
            $historyError = __('The alert history could not be read.');
        }
        $rules = $this->alerts->rules($user);
        $channels = $this->alerts->channels($user);

        return view('alerts.index', [
            'scope' => $user,
            'open' => $this->alerts->open($user),
            'rules' => $rules,
            'channels' => $channels,
            'history' => $history['items'],
            'total' => $history['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($history['total'] / self::PAGE_SIZE)),
            'filters' => ['rule' => $filters['rule'] ?? '', 'severity' => $filters['severity'] ?? '', 'period' => $period],
            'historyError' => $historyError,
            'tab' => $filters['tab'] ?? ($request->hasAny(['rule', 'severity', 'period', 'page']) ? 'history' : 'open'),
            'canManage' => $policy->manage($principal, $user),
            'base' => self::routeBase($user),
        ]);
    }

    public function updateRule(Request $request, Principal $principal, AlertPolicy $policy): RedirectResponse
    {
        [$user, $rule] = [self::param($request, 'user'), self::param($request, 'rule')];
        $this->authorizeChange($principal, $policy, $user);
        $rules = $this->alerts->rules($user);
        abort_unless(isset($rules[$rule]), 404);
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'warning' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'critical' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'for' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'severity' => ['nullable', Rule::in(AlertStore::SEVERITIES)],
            'cooldown' => ['required', 'integer', 'min:1', 'max:43200'],
            'channels' => ['nullable', 'array', 'max:20'],
            'channels.*' => ['string', 'regex:/^\d{14}$/'],
            'options' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\/@,-]*$/'],
        ]);
        $result = $this->alerts->changeRule($user, $rule, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'warning' => $data['warning'] ?? '',
            'critical' => $data['critical'] ?? '',
            'for' => (string) ($data['for'] ?? 0),
            'severity' => $data['severity'] ?? '',
            'cooldown' => (string) $data['cooldown'],
            'channels' => array_values($data['channels'] ?? []),
            'options' => $data['options'] ?? '',
        ]);
        $back = redirect()->to($this->url($user, 'rules'));

        return $result->ok()
            ? $back->with('ok', sprintf(__('Rule %s has been saved.'), $rules[$rule]['TITLE'] ?? $rule))
            : $back->with('error', AlertStore::reason($result));
    }

    public function storeChannel(Request $request, string $user, Principal $principal, AlertPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[^\'"$`\\\\\x00-\x1f]+$/'],
            'type' => ['required', Rule::in(AlertStore::CHANNEL_TYPES)],
            'target' => ['nullable', 'string', 'max:512'],
            'secret' => [Rule::requiredIf(in_array($request->input('type'), AlertStore::SECRET_TYPES, true)), 'nullable', 'string', 'max:4096'],
        ]);
        $result = $this->alerts->addChannel($user, $data['name'], $data['type'],
            in_array($data['type'], ['email', 'telegram'], true) ? (string) ($data['target'] ?? '') : '',
            in_array($data['type'], AlertStore::SECRET_TYPES, true) ? (string) $data['secret'] : null);
        if (! $result->ok()) {
            return redirect()->to($this->url($user, 'channels'))->withInput($request->except('secret'))
                ->with('error', AlertStore::reason($result));
        }

        return redirect()->to($this->url($user, 'channels'))->with('ok', sprintf(__('Channel %s has been added.'), $data['name']));
    }

    public function updateChannel(Request $request, Principal $principal, AlertPolicy $policy): RedirectResponse
    {
        [$user, $id] = [self::param($request, 'user'), self::param($request, 'id')];
        $this->authorizeChange($principal, $policy, $user);
        $channel = $this->channel($user, $id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[^\'"$`\\\\\x00-\x1f]+$/'],
            'enabled' => ['nullable', 'boolean'],
            'target' => ['nullable', 'string', 'max:512'],
            'secret' => ['nullable', 'string', 'max:4096'],
        ]);
        $type = $channel['TYPE'];
        $target = in_array($type, ['email', 'telegram'], true) ? (string) ($data['target'] ?? '') : null;
        $secret = in_array($type, AlertStore::SECRET_TYPES, true) ? ($data['secret'] ?? null) : null;
        $result = $this->alerts->changeChannel($user, $id, $data['name'], (bool) ($data['enabled'] ?? false), $target, $secret);
        $back = redirect()->to($this->url($user, 'channels'));

        return $result->ok()
            ? $back->with('ok', sprintf(__('Channel %s has been saved.'), $data['name']))
            : $back->with('error', AlertStore::reason($result));
    }

    public function destroyChannel(Request $request, Principal $principal, AlertPolicy $policy): RedirectResponse
    {
        [$user, $id] = [self::param($request, 'user'), self::param($request, 'id')];
        $this->authorizeChange($principal, $policy, $user);
        $channel = $this->channel($user, $id);
        $result = $this->alerts->deleteChannel($user, $id);
        $back = redirect()->to($this->url($user, 'channels'));

        return $result->ok()
            ? $back->with('ok', sprintf(__('Channel %s has been deleted.'), $channel['NAME']))
            : $back->with('error', AlertStore::reason($result));
    }

    public function testChannel(Request $request, Principal $principal, AlertPolicy $policy): RedirectResponse
    {
        [$user, $id] = [self::param($request, 'user'), self::param($request, 'id')];
        $this->authorizeChange($principal, $policy, $user);
        $channel = $this->channel($user, $id);
        $result = $this->alerts->testChannel($user, $id);
        $back = redirect()->to($this->url($user, 'channels'));

        return $result['ok']
            ? $back->with('ok', sprintf(__('A test message went to %s.'), $channel['NAME']))
            : $back->with('error', sprintf(__('The test message to %s failed: %s'), $channel['NAME'], $result['error']));
    }

    /**
     * The route parameters by name: the server routes give `user` as a
     * default, which Laravel passes after the URI parameters, so methods
     * with two parameters read them from the route.
     */
    private static function param(Request $request, string $name): string
    {
        return (string) $request->route($name);
    }

    /** The route name prefix of a scope's pages. */
    public static function routeBase(string $user): string
    {
        return $user === 'system' ? 'server.alerts' : 'alerts';
    }

    /** The page of a scope, on TAB. */
    private function url(string $user, string $tab): string
    {
        $url = $user === 'system' ? route('server.alerts', ['tab' => $tab]) : route('alerts.show', ['user' => $user, 'tab' => $tab]);

        return $url.'#'.$tab;
    }

    private function authorizeChange(Principal $principal, AlertPolicy $policy, string $user): void
    {
        abort_unless($policy->view($principal, $user), $user === 'system' ? 403 : 404);
        abort_unless($policy->manage($principal, $user), 403);
    }

    /** @return array<string, string> */
    private function channel(string $user, string $id): array
    {
        abort_unless(preg_match('/^\d{14}$/', $id) === 1, 404);

        return $this->alerts->channels($user)[$id] ?? abort(404);
    }
}
