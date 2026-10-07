<?php

namespace App\Http\Controllers\Users;

use App\Arc\ArcCommandException;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Policies\FilePolicy;
use App\Policies\ImpersonationPolicy;
use App\Policies\UserPolicy;
use App\Services\Docker\DockerApps;
use App\Services\Docker\DockerAppService;
use App\Services\Mail\AccountMailer;
use App\Services\Node\NodeApps;
use App\Services\Node\NodeAppService;
use App\Services\Ports\PortService;
use App\Services\Users\UserActions;
use App\Services\Users\UserRecord;
use App\Services\Users\UserService;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainService;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Users (administrators) and customers (resellers): list, detail and the
 * create wizard (E11 spec §7.1–7.3).
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
    ) {}

    public function index(Request $request, Principal $principal, UserPolicy $policy, ImpersonationPolicy $impersonation): View
    {
        abort_unless($policy->viewAny($principal), 403);
        $role = $principal->actingRole();
        $isAdmin = $role === Role::Admin;

        $records = $isAdmin
            ? $this->users->all()
            : $this->users->only($this->tenancy->customersOf($principal->effectiveUser()));
        if ($isAdmin && $this->config->is('POLICY_SYSTEM_HIDE_ADMIN') && $principal->loginUser !== $this->config->get('ROOT_USER')) {
            unset($records[$this->config->get('ROOT_USER')]);
        }

        $roleFilter = (string) $request->query('role', '');
        $ownerFilter = $isAdmin ? (string) $request->query('owner', '') : '';
        $shown = array_filter($records, fn (UserRecord $record) => ($roleFilter === '' || $record->role()->value === $roleFilter)
            && ($ownerFilter === '' || $record->owner === $ownerFilter));

        $abilities = [];
        foreach ($shown as $name => $record) {
            $abilities[$name] = $policy->abilities($principal, $name, $impersonation);
        }

        return view('users.index', [
            'records' => $shown,
            'abilities' => $abilities,
            'isAdmin' => $isAdmin,
            'isReseller' => $role === Role::Reseller,
            'roleFilter' => $roleFilter,
            'ownerFilter' => $ownerFilter,
            'resellers' => $isAdmin ? $this->tenancy->resellers() : [],
            'tree' => $isAdmin ? $this->tree($records) : [],
            'rootUser' => $this->config->get('ROOT_USER'),
            'canCreate' => $policy->create($principal),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => array_filter([
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d users?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d users? All their data is removed.')],
            ]),
        ]);
    }

    public function show(string $user, Principal $principal, UserPolicy $policy, ImpersonationPolicy $impersonation, WebDomainService $web, PortService $ports, NodeAppService $nodeApps, FilePolicy $files, SysConfig $config, DockerAppService $dockerApps): View
    {
        abort_unless($policy->view($principal, $user), 404);
        $record = $this->users->find($user);
        $isReseller = $record->role() === Role::Reseller;

        return view('users.show', [
            'record' => $record,
            'can' => $policy->abilities($principal, $user, $impersonation),
            'domains' => $web->forUser($user),
            'isReseller' => $isReseller,
            'allocation' => $isReseller ? $this->users->allocation($record) : [],
            'customers' => $isReseller ? $this->users->only($this->tenancy->customersOf($user)) : [],
            'ports' => $ports->account($user),
            'nodeApps' => $this->nodeAppsOf($user, $nodeApps),
            'dockerApps' => $config->is('DOCKER_SYSTEM') ? $this->dockerAppsOf($user, $dockerApps) : null,
            'packageLabel' => UserService::packageLabel($record->package(), $principal->actingRole() === Role::Reseller ? $principal->effectiveUser() : ''),
            // File manager (E3 spec §7.7)
            'filesUrl' => $config->is('FILE_MANAGER', 'true') && $files->view($principal, $user) ? route('files.show', ['user' => $user]) : null,
        ]);
    }

    public function create(Principal $principal, UserPolicy $policy, UserActions $actions): View
    {
        abort_unless($policy->create($principal), 403);
        $role = $principal->actingRole();
        $packages = $this->users->assignablePackages($role, $principal->effectiveUser());
        $languages = $this->users->languages();

        return view('users.create', [
            'isAdmin' => $role === Role::Admin,
            'isReseller' => $role === Role::Reseller,
            'packages' => $this->packageOptions($packages, $principal),
            'defaultPackage' => array_key_exists('default', $packages) ? 'default' : (string) array_key_first($packages),
            'languages' => $languages,
            'defaultLanguage' => $this->config->get('LANGUAGE', 'en') ?: 'en',
            'owners' => $role === Role::Admin ? $actions->ownerOptions() : [],
            'rootUser' => $this->config->get('ROOT_USER'),
            'hasDns' => $this->config->get('DNS_SYSTEM') !== '',
            'hasMail' => $this->config->get('MAIL_SYSTEM') !== '',
            'hasDb' => $this->config->get('DB_SYSTEM') !== '',
            'hasWeb' => $this->config->get('WEB_SYSTEM') !== '',
            'quotaUrl' => $role === Role::Reseller ? route('users.quota') : null,
        ]);
    }

    public function store(StoreUserRequest $request, Principal $principal, UserPolicy $policy, UserActions $actions, WebDomainService $web, AccountMailer $mailer): RedirectResponse
    {
        abort_unless($policy->create($principal), 403);
        $role = $principal->actingRole();
        ['input' => $input, 'steps' => $steps, 'counters' => $counters] = $actions->planCreate($principal, $request->account());

        $result = $this->users->apply($steps);
        if (! $result->ok()) {
            if ($result->applied === []) {
                return back()->withInput($request->except(['password', 'db_password']))
                    ->with('error', $result->error);
            }

            return $this->partial($input['username'], $result->failed, $result->error);
        }
        $this->users->updateCounters($counters);

        // Optional first web domain and database, as the owner
        $user = $input['username'];
        $extra = [];
        if ($request->filled('domain') && $this->config->get('WEB_SYSTEM') !== '') {
            $userIps = $web->userIps($user);
            $ip = (string) array_key_first($userIps);
            if ($ip !== '') {
                $extra = $web->planCreate($user, (string) $request->input('domain'), $ip, $request->boolean('dns'), $request->boolean('mail'), $userIps);
            }
        }
        if ($request->filled('database') && $this->config->get('DB_SYSTEM') !== '') {
            $extra[] = new Step(sprintf(__('Add database %s'), $user.'_'.$request->input('database')), 'v-add-database', [
                $user, (string) $request->input('database'), (string) $request->input('db_user'), Secret::file((string) $request->input('db_password')),
            ]);
        }
        if ($extra !== []) {
            $extraResult = $web->apply($extra);
            if (! $extraResult->ok()) {
                return $this->partial($user, $extraResult->failed, $extraResult->error);
            }
        }

        if ($request->filled('notify')) {
            $mailer->sendAccountCredentials((string) $request->input('notify'), $user, $input['name'], $input['password'], $input['language']);
        }

        return redirect()->route('users.show', ['user' => $user])
            ->with('ok', $role === Role::Reseller ? __('Customer has been created.') : __('User has been created.'));
    }

    /** GET /app/users/quota?package=&owner= : live quota feedback of the wizard (JSON). */
    public function quota(Request $request, Principal $principal, UserPolicy $policy): JsonResponse
    {
        abort_unless($policy->create($principal), 403);
        $package = (string) $request->query('package', '');
        abort_unless(preg_match('/^[[:alnum:]][-.\w]*$/', $package) === 1, 422);

        $reseller = $principal->actingRole() === Role::Reseller ? $principal->effectiveUser() : (string) $request->query('owner', '');
        if ($reseller === '' || ! $this->tenancy->isReseller($reseller)) {
            return response()->json(['ok' => true]);
        }
        $key = $this->users->quotaProblem($reseller, $package);

        return response()->json($key === null
            ? ['ok' => true]
            : ['ok' => false, 'key' => $key, 'message' => UserActions::quotaMessage($key)]);
    }

    /** The account exists, a later step failed: go to it and name the step. */
    private function partial(string $user, ?Step $failed, string $error): RedirectResponse
    {
        return redirect()->route('users.show', ['user' => $user])
            ->with('error', sprintf(__('%s failed'), $failed?->label ?? ''))
            ->with('error_detail', $error);
    }

    /**
     * @param  array<string, array<string, string>>  $packages
     * @return array<string, string> name => label
     */
    private function packageOptions(array $packages, Principal $principal): array
    {
        $reseller = $principal->actingRole() === Role::Reseller ? $principal->effectiveUser() : '';
        $options = [];
        foreach (array_keys($packages) as $name) {
            $options[$name] = UserService::packageLabel($name, $reseller);
        }

        return $options;
    }

    /**
     * Admin tree: resellers with their customers, then the other users.
     *
     * @param  array<string, UserRecord>  $records
     * @return list<array{record: UserRecord, children: list<UserRecord>}>
     */
    private function tree(array $records): array
    {
        $tree = [];
        foreach ($records as $record) {
            if ($record->role() === Role::Reseller) {
                $tree[$record->name] = ['record' => $record, 'children' => []];
            }
        }
        $rest = [];
        foreach ($records as $record) {
            if ($record->role() === Role::User && isset($tree[$record->owner])) {
                $tree[$record->owner]['children'][] = $record;
            } elseif ($record->role() !== Role::Reseller) {
                $rest[] = ['record' => $record, 'children' => []];
            }
        }

        return [...array_values($tree), ...$rest];
    }

    /** The Docker apps of the account for its detail page (E4), null when they cannot be read. */
    private function dockerAppsOf(string $user, DockerAppService $dockerApps): ?DockerApps
    {
        try {
            return $dockerApps->apps($user);
        } catch (ArcCommandException) {
            return null;
        }
    }

    /** The Node.js apps of the account for its detail page (E14), null when they cannot be read. */
    private function nodeAppsOf(string $user, NodeAppService $nodeApps): ?NodeApps
    {
        try {
            return $nodeApps->apps($user);
        } catch (ArcCommandException) {
            return null;
        }
    }
}
