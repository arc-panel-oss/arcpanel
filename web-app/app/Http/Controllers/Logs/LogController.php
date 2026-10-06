<?php

namespace App\Http\Controllers\Logs;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\LogPolicy;
use App\Services\Logs\LogService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Action log and login history of an account (web/list/log, web/list/log/
 * auth, web/delete/log, web/delete/log/auth), E7 batch 2 spec §9.1–§9.2.
 */
final class LogController extends Controller
{
    public function __construct(private readonly LogService $logs) {}

    /** /logs: the effective account, or the owner an administrator or reseller picked. */
    public function entry(Request $request, OwnerList $owners): RedirectResponse
    {
        return redirect()->route('logs.show', ['user' => $owners->forCreate($request->query('owner'))]);
    }

    public function show(string $user, Principal $principal, LogPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);

        return view('logs.actions', [
            'user' => $user,
            'rows' => $this->logs->actions($user),
            'owners' => $owners->pickable(),
            'canDelete' => $policy->delete($principal, $user),
            'canViewLogins' => $policy->viewAuth($principal, $user),
        ]);
    }

    public function logins(string $user, Principal $principal, LogPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->viewAuth($principal, $user), 403);

        return view('logs.logins', [
            'user' => $user,
            'rows' => $this->logs->logins($user),
            'owners' => $owners->pickable(),
            'canDelete' => $policy->deleteAuth($principal, $user),
        ]);
    }

    public function destroy(string $user, Principal $principal, LogPolicy $policy): RedirectResponse
    {
        abort_unless($policy->delete($principal, $user), 403);

        $result = $this->logs->clearActions($user);
        $back = redirect()->route('logs.show', ['user' => $user]);

        return $result->ok() ? $back->with('ok', __('Logs have been deleted.')) : $back->with('error', $result->errorMessage());
    }

    public function destroyLogins(string $user, Principal $principal, LogPolicy $policy): RedirectResponse
    {
        abort_unless($policy->deleteAuth($principal, $user), 403);

        $result = $this->logs->clearLogins($user);
        $back = redirect()->route('logs.auth', ['user' => $user]);

        return $result->ok() ? $back->with('ok', __('Logs have been deleted.')) : $back->with('error', $result->errorMessage());
    }
}
