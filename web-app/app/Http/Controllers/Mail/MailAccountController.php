<?php

namespace App\Http\Controllers\Mail;

use App\Auth\ClientIp;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\AccountAutoreplyRequest;
use App\Http\Requests\Mail\AccountForwardingRequest;
use App\Http\Requests\Mail\AccountGeneralRequest;
use App\Http\Requests\Mail\StoreMailAccountRequest;
use App\Policies\MailPolicy;
use App\Services\Mail\HostingCredentialsMailer;
use App\Services\MailDomains\MailAccountPlanner;
use App\Services\MailDomains\MailDomainRecord;
use App\Services\MailDomains\MailService;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mail accounts of a domain (web/list/mail?domain=, web/add/mail?domain=,
 * web/edit/mail?domain=&account=): list, creation and the edit tabs.
 */
final class MailAccountController extends Controller
{
    public function __construct(
        private readonly MailService $mail,
        private readonly MailAccountPlanner $planner,
    ) {}

    public function index(string $user, string $domain, Principal $principal, MailPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $can = $policy->abilities($principal, $user);

        return view('mail.accounts', [
            'record' => $record,
            'accounts' => $this->mail->accounts($user, $domain),
            'can' => $can,
            'canBulk' => $can['update'] || $can['delete'],
            'webmailUrl' => $this->mail->openWebmailUrl($record),
            'signOn' => fn ($account) => $can['update'] && ! $principal->isReadOnly() && $this->mail->signOnUrl($record, $account) !== null,
            'bulkActions' => [
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d mail accounts?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d mail accounts? Their mail is removed.')],
            ],
        ]);
    }

    public function create(string $user, string $domain, Principal $principal, MailPolicy $policy): View
    {
        abort_unless($policy->create($principal, $user), 403);
        $record = $this->mail->find($user, $domain);

        return view('mail.account-create', [
            'record' => $record,
            'can' => $policy->abilities($principal, $user),
            'client' => $this->clientSettings($record),
        ]);
    }

    public function store(StoreMailAccountRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy, HostingCredentialsMailer $mailer): RedirectResponse
    {
        abort_unless($policy->create($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $values = $request->values($policy->updateRateLimit($principal, $user));

        $result = $this->mail->apply($this->planner->planCreate($user, $domain, $values));
        $account = $values['account'];
        if (! $result->ok()) {
            // The account exists once its first step ran: show it with the failed step.
            return $result->changed()
                ? redirect()->route('mail.accounts.edit', ['user' => $user, 'domain' => $domain, 'account' => $account])
                    ->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error)
                : back()->withInput($request->except('password'))->with('error', $result->error);
        }

        if ($request->filled('email')) {
            $this->mailCredentials($mailer, (string) $request->input('email'), $record, $account, $values['password'], $principal->language);
        }

        return redirect()->route('mail.accounts.index', ['user' => $user, 'domain' => $domain])
            ->with('ok', sprintf(__('Mail account %s@%s has been created successfully.'), $account, $domain));
    }

    public function edit(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $current = $this->mail->findAccount($user, $domain, $account);

        return view('mail.account-edit', [
            'record' => $record,
            'account' => $current,
            'message' => $this->mail->autoreplyMessage($current),
            'can' => $policy->abilities($principal, $user),
            'client' => $this->clientSettings($record),
            'webmailUrl' => $this->mail->openWebmailUrl($record),
            'signOn' => $policy->update($principal, $user) && ! $principal->isReadOnly() && $this->mail->signOnUrl($record, $current) !== null,
        ]);
    }

    /**
     * "Open Webmail (signed in)": a one-time ticket posted to Roundcube, for
     * whoever may change the account password (E46 spec §7.7, D11).
     */
    public function webmail(Request $request, string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): View
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $current = $this->mail->findAccount($user, $domain, $account);
        abort_unless($this->mail->signOnUrl($record, $current) !== null, 404);

        return view('mail.webmail-sso', $this->mail->signOnForm($record, $current, $principal->loginUser, ClientIp::of($request)));
    }

    public function general(AccountGeneralRequest $request, string $user, string $domain, string $account, Principal $principal, MailPolicy $policy, HostingCredentialsMailer $mailer): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $current = $this->mail->findAccount($user, $domain, $account);
        $values = $request->values($policy->updateRateLimit($principal, $user));

        $result = $this->mail->apply($this->planner->planGeneral($current, $values));
        if ($result->ok() && $request->filled('email')) {
            $this->mailCredentials($mailer, (string) $request->input('email'), $record, $account, $values['password'], $principal->language);
        }

        return $this->finish($user, $domain, $account, 'general', $result);
    }

    public function forwarding(AccountForwardingRequest $request, string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $current = $this->mail->findAccount($user, $domain, $account);

        return $this->finish($user, $domain, $account, 'forwarding', $this->mail->apply($this->planner->planForwarding($current, $request->values())));
    }

    public function autoreply(AccountAutoreplyRequest $request, string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $current = $this->mail->findAccount($user, $domain, $account);
        $steps = $this->planner->planAutoreply($current, $this->mail->autoreplyMessage($current), $request->values());

        return $this->finish($user, $domain, $account, 'autoreply', $this->mail->apply($steps));
    }

    /**
     * The client configuration panel (templates/includes/email-settings-panel.php).
     *
     * @return array{domain: string, webmail: string|null, hostname: string}
     */
    private function clientSettings(MailDomainRecord $record): array
    {
        return [
            'domain' => $record->domain,
            'webmail' => $this->mail->clientWebmailUrl($record),
            'hostname' => 'mail.'.$record->domain,
        ];
    }

    /** The "email_credentials" mail of web/add/mail and web/edit/mail (D12). */
    private function mailCredentials(HostingCredentialsMailer $mailer, string $to, MailDomainRecord $record, string $account, string $password, string $language): void
    {
        $mailer->sendMailCredentials($to, [
            'account' => $account,
            'domain' => $record->domain,
            'password' => $password,
            'webmail' => $this->mail->webmailUrl($record),
            'hostname' => 'mail.'.$record->domain,
        ], $language);
    }

    /** Back to the tab with a toast; a failure names the step that failed. */
    private function finish(string $user, string $domain, string $account, string $tab, ApplyResult $result): RedirectResponse
    {
        $redirect = redirect()->to(route('mail.accounts.edit', ['user' => $user, 'domain' => $domain, 'account' => $account]).'#'.$tab);
        if (! $result->ok()) {
            /** @var Step $failed */
            $failed = $result->failed;

            // Without the token, the other tabs show the stored values, not this form's.
            return $redirect->withInput(request()->except(['_token', 'password']))
                ->with('error', sprintf(__('%s failed'), $failed->label))
                ->with('error_detail', $result->error);
        }

        return $redirect->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }
}
