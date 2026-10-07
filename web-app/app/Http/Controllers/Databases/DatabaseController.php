<?php

namespace App\Http\Controllers\Databases;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreDatabaseRequest;
use App\Http\Requests\Databases\UpdateDatabaseRequest;
use App\Policies\Capabilities;
use App\Policies\DatabasePolicy;
use App\Services\Databases\DatabaseActions;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\DbAdminLinks;
use App\Services\Mail\HostingCredentialsMailer;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Database list, creation and edit (web/list/db, web/add/db, web/edit/db).
 */
final class DatabaseController extends Controller
{
    /** Lists longer than this are paged on the server. */
    public const PAGE_SIZE = 500;

    public function __construct(
        private readonly DatabaseService $databases,
        private readonly DatabaseActions $actions,
    ) {}

    public function index(Request $request, Principal $principal, DatabasePolicy $policy, OwnerList $ownerList, DbAdminLinks $links): View
    {
        abort_unless($policy->viewAny($principal), 403);

        $selection = $ownerList->forList($request->query('owner'));
        $records = $this->databases->forUsers($selection->users());
        $total = count($records);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $records = array_slice($records, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $abilities = [];
        foreach ($records as $record) {
            $abilities[$record->user] ??= $policy->abilities($principal, $record->user);
        }

        $role = $principal->actingRole();

        return view('db.index', [
            'records' => $records,
            'abilities' => $abilities,
            'showOwner' => $selection->showOwner(),
            'everyUser' => $selection->everyUser,
            'owners' => $selection->owners,
            'owner' => $selection->owner,
            'links' => $links->toolbar($this->databases->types()),
            'sso' => $links->ssoAvailable(),
            'canCreate' => Capabilities::allows($role, 'db.create') && ! $principal->isReadOnly(),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => array_filter([
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d databases?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'rebuild' => $role === Role::Admin ? ['label' => __('Rebuild'), 'icon' => 'refresh'] : null,
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d databases? Their data is removed.')],
            ]),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request, Principal $principal, DatabasePolicy $policy, OwnerList $ownerList): View
    {
        $owner = $ownerList->forCreate($request->query('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        $types = $this->databases->types();

        return view('db.create', [
            'owner' => $owner,
            'owners' => $ownerList->pickable(),
            'ownerIsAdmin' => $ownerList->isAdmin($owner),
            'types' => array_combine($types, $types),
            'hosts' => $this->databases->hosts(),
            'charsets' => array_combine(DatabaseService::CHARSETS, DatabaseService::CHARSETS),
        ]);
    }

    public function store(StoreDatabaseRequest $request, Principal $principal, DatabasePolicy $policy, OwnerList $ownerList, DbAdminLinks $links, HostingCredentialsMailer $mailer): RedirectResponse
    {
        $owner = $ownerList->forCreate($request->input('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        $type = (string) $request->input('type');
        $host = (string) $request->input('host');
        $this->actions->assertAllowed('type', $type, $this->databases->types(), __('Please select a valid database type.'));
        $this->actions->assertAllowed('host', $host, $this->databases->hosts(), __('Please select a valid database host.'));

        $name = (string) $request->input('database');
        $dbUser = (string) $request->input('dbuser');
        $password = (string) $request->input('password');
        $result = $this->databases->apply([
            $this->databases->planCreate($owner, $name, $dbUser, $password, $type, $host, (string) $request->input('charset')),
        ]);
        if (! $result->ok()) {
            return back()->withInput($request->except('password'))->with('error', $result->error);
        }

        // The CLI prefixed both names with the owner (and lowercases PostgreSQL names).
        $database = $owner.'_'.$name;
        $fullUser = $owner.'_'.$dbUser;
        if ($type === 'pgsql') {
            $database = strtolower($database);
            $fullUser = strtolower($fullUser);
        }
        $admin = $links->adminUrl($type);

        if ($request->filled('email')) {
            $mailer->sendDatabaseCredentials((string) $request->input('email'), $database, $fullUser, $password, $admin, $principal->language);
        }

        return redirect()->route('db.edit', ['user' => $owner, 'database' => $database])
            ->with('ok', sprintf(__('Database %s has been created successfully.'), $database))
            ->with('ok_link', ['url' => $admin, 'label' => sprintf(__('Open %s'), $type === 'pgsql' ? 'phpPgAdmin' : 'phpMyAdmin')]);
    }

    public function edit(string $user, string $database, Principal $principal, DatabasePolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->databases->find($user, $database);

        return view('db.edit', [
            'record' => $record,
            'can' => $policy->abilities($principal, $user),
        ]);
    }

    public function update(UpdateDatabaseRequest $request, string $user, string $database, Principal $principal, DatabasePolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->databases->find($user, $database);

        $result = $this->databases->apply(
            $this->databases->planUpdate($record, (string) $request->input('dbuser'), (string) $request->input('password', '')),
        );
        $back = redirect()->route('db.edit', ['user' => $user, 'database' => $database]);
        if (! $result->ok()) {
            return $back->withInput($request->except('password'))
                ->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error);
        }

        return $back->with('ok', __('Changes have been saved.'));
    }
}
