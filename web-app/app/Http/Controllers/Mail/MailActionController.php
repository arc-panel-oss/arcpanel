<?php

namespace App\Http\Controllers\Mail;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Concerns\ChecksBulkItems;
use App\Http\Controllers\Controller;
use App\Policies\MailPolicy;
use App\Services\MailDomains\MailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Single and bulk actions on mail domains and accounts (web/bulk/mail,
 * web/suspend/mail, web/unsuspend/mail, web/delete/mail).
 */
final class MailActionController extends Controller
{
    use ChecksBulkItems;

    public function __construct(private readonly MailService $mail) {}

    public function suspend(string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $user, $domain, $policy->suspend($principal, $user));
    }

    public function unsuspend(string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $user, $domain, $policy->suspend($principal, $user));
    }

    public function destroy(string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        return $this->single('delete', $user, $domain, $policy->delete($principal, $user));
    }

    /** POST /app/mail/bulk {action, items[]} with items "user/domain". */
    public function bulk(Request $request, Principal $principal, MailPolicy $policy, OwnerScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(MailService::BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'max:300', 'regex:/^[[:alnum:]][-.\w]*\/[^\/\s]{1,253}$/'],
        ]);
        $action = $data['action'];
        $items = $this->bulkItems($data['items'], 2, $scope, fn (string $owner) => $policy->action($action, $principal, $owner));

        return $this->mail->bulk($action, $items)
            ->flash(redirect()->route('mail.index', array_filter(['owner' => $request->query('owner')])));
    }

    public function suspendAccount(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        return $this->singleAccount('suspend', $user, $domain, $account, $policy->suspend($principal, $user));
    }

    public function unsuspendAccount(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        return $this->singleAccount('unsuspend', $user, $domain, $account, $policy->suspend($principal, $user));
    }

    public function destroyAccount(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        return $this->singleAccount('delete', $user, $domain, $account, $policy->delete($principal, $user));
    }

    /**
     * POST /app/mail/{user}/{domain}/accounts/bulk {action, items[]} with the
     * account names of the domain; the owner and the domain come from the URL.
     */
    public function bulkAccounts(Request $request, string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(MailService::ACCOUNT_BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'regex:/^[\w.-]{1,64}$/'],
        ]);
        $action = $data['action'];
        abort_unless($policy->action($action, $principal, $user), 403);
        $this->mail->find($user, $domain);

        return $this->mail->bulkAccounts($action, $user, $domain, array_values(array_unique($data['items'])))
            ->flash(redirect()->route('mail.accounts.index', ['user' => $user, 'domain' => $domain]));
    }

    private function single(string $action, string $user, string $domain, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $this->mail->find($user, $domain);

        $result = $this->mail->single($action, $user, $domain);
        $back = $action === 'delete'
            ? redirect()->route('mail.index')
            : redirect()->back(fallback: route('mail.index'));

        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        return $back->with('ok', match ($action) {
            'suspend' => sprintf(__('Mail domain %s has been suspended.'), $domain),
            'unsuspend' => sprintf(__('Mail domain %s has been unsuspended.'), $domain),
            default => sprintf(__('Mail domain %s has been deleted.'), $domain),
        });
    }

    private function singleAccount(string $action, string $user, string $domain, string $account, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $record = $this->mail->findAccount($user, $domain, $account);

        $result = $this->mail->bulkAccounts($action, $user, $domain, [$account]);
        $params = ['user' => $user, 'domain' => $domain];
        $back = $action === 'delete'
            ? redirect()->route('mail.accounts.index', $params)
            : redirect()->back(fallback: route('mail.accounts.index', $params));
        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        return $back->with('ok', match ($action) {
            'suspend' => sprintf(__('Mail account %s has been suspended.'), $record->email()),
            'unsuspend' => sprintf(__('Mail account %s has been unsuspended.'), $record->email()),
            default => sprintf(__('Mail account %s has been deleted.'), $record->email()),
        });
    }
}
