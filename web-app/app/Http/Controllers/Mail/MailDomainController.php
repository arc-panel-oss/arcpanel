<?php

namespace App\Http\Controllers\Mail;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreMailDomainRequest;
use App\Policies\Capabilities;
use App\Policies\DnsPolicy;
use App\Policies\MailPolicy;
use App\Services\Dns\DnsService;
use App\Services\MailDomains\MailActions;
use App\Services\MailDomains\MailDomainPlanner;
use App\Services\MailDomains\MailService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mail domain list, creation, edit page and DNS hints (web/list/mail,
 * web/add/mail, web/edit/mail, web/list/mail?dns=1).
 */
final class MailDomainController extends Controller
{
    /** Lists longer than this are paged on the server. */
    public const PAGE_SIZE = 500;

    public function __construct(private readonly MailService $mail) {}

    public function index(Request $request, Principal $principal, MailPolicy $policy, OwnerList $ownerList): View
    {
        abort_unless($policy->viewAny($principal), 403);

        $selection = $ownerList->forList($request->query('owner'));
        $records = $this->mail->forUsers($selection->users());
        $total = count($records);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $records = array_slice($records, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $abilities = [];
        foreach ($records as $record) {
            $abilities[$record->user] ??= $policy->abilities($principal, $record->user);
        }

        $role = $principal->actingRole();

        return view('mail.index', [
            'records' => $records,
            'abilities' => $abilities,
            'showOwner' => $selection->showOwner(),
            'everyUser' => $selection->everyUser,
            'owners' => $selection->owners,
            'owner' => $selection->owner,
            'webmail' => $this->mail->hasWebmail(),
            'webmailAlias' => $this->mail->webmailAlias(),
            'webmailPort' => $this->mail->webmailPortUrl(),
            'canCreate' => Capabilities::allows($role, 'mail.create') && ! $principal->isReadOnly(),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => array_filter([
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d mail domains?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'rebuild' => $role === Role::Admin ? ['label' => __('Rebuild'), 'icon' => 'refresh'] : null,
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d mail domains? Their accounts and mail are removed.')],
            ]),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request, Principal $principal, MailPolicy $policy, OwnerList $ownerList): View
    {
        $owner = $ownerList->forCreate($request->query('owner'));
        abort_unless($policy->create($principal, $owner), 403);
        $systems = $this->mail->systems();

        return view('mail.create', [
            'owner' => $owner,
            'owners' => $ownerList->pickable(),
            'ownerIsAdmin' => $ownerList->isAdmin($owner),
            'systems' => $systems,
            'clients' => $systems['webmail'] ? $this->mail->webmailClients() : [],
        ]);
    }

    public function store(StoreMailDomainRequest $request, Principal $principal, MailPolicy $policy, OwnerList $ownerList, MailDomainPlanner $planner): RedirectResponse
    {
        $owner = $ownerList->forCreate($request->input('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        $systems = $this->mail->systems();
        $values = $request->values();
        if ($systems['webmail']) {
            app(MailActions::class)->assertWebmail($values['webmail']);
        }

        $result = $this->mail->apply($planner->planCreate($owner, $values, $systems));
        $accounts = redirect()->route('mail.accounts.index', ['user' => $owner, 'domain' => $values['domain']]);
        if (! $result->ok()) {
            // The domain exists once its first step ran: show it with the failed step.
            return $result->changed()
                ? $accounts->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error)
                : back()->withInput($request->except('relay_password'))->with('error', $result->error);
        }

        return $accounts->with('ok', sprintf(__('Mail domain %s has been created successfully.'), $values['domain']));
    }

    public function edit(string $user, string $domain, Principal $principal, MailPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $systems = $this->mail->systems();

        return view('mail.edit', [
            'record' => $record,
            'ssl' => $record->ssl ? $this->mail->ssl($user, $domain) : null,
            'can' => $policy->abilities($principal, $user),
            'systems' => $systems,
            'clients' => $systems['webmail'] ? $this->mail->webmailClients() : [],
            'webmailAlias' => $record->webmailAlias !== '' ? $record->webmailAlias : $this->mail->webmailAlias(),
            'isAdminView' => $user !== $principal->effectiveUser(),
        ]);
    }

    /** The DNS records the domain needs (web/list/mail?domain=&dns=1). */
    public function dns(string $user, string $domain, Principal $principal, MailPolicy $policy, DnsPolicy $dnsPolicy, DnsService $dns, SysConfig $config): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->mail->find($user, $domain);

        // "Open DNS zone" when the owner has a zone of the same name
        $zoneUrl = null;
        if ($config->get('DNS_SYSTEM') !== '' && $dnsPolicy->view($principal, $user) && isset($dns->forUser($user)[$domain])) {
            $zoneUrl = route('dns.records.index', ['user' => $user, 'domain' => $domain]);
        }

        return view('mail.dns', [
            'record' => $record,
            'rows' => $this->mail->dnsHints($record),
            'zoneUrl' => $zoneUrl,
        ]);
    }
}
