<?php

namespace App\Http\Controllers\WebDomains;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebDomains\StoreWebDomainRequest;
use App\Policies\Capabilities;
use App\Policies\WebDomainPolicy;
use App\Services\UserDirectory;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\Tabs\AdvancedTab;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web domain list and creation (web/list/web, web/add/web).
 */
final class WebDomainController extends Controller
{
    /** Lists longer than this are paged on the server. */
    public const PAGE_SIZE = 500;

    public function index(Request $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope, WebDomainService $web, UserDirectory $directory): View
    {
        abort_unless($policy->viewAny($principal), 403);

        $visible = $scope->visibleUsers();
        $allUsers = $visible === null;
        $owners = $allUsers ? $directory->names() : $visible;

        $owner = trim((string) $request->query('owner', ''));
        if ($owner !== '') {
            $scope->assertVisible($owner);
            abort_unless(in_array($owner, $owners, true), 404);
        }

        $records = $web->forUsers($owner !== '' ? [$owner] : $owners);
        $total = count($records);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $records = array_slice($records, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $abilities = [];
        foreach ($records as $record) {
            $abilities[$record->user] ??= $policy->abilities($principal, $record->user);
        }

        $role = $principal->actingRole();
        $bulkActions = array_filter([
            'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d web domains?')],
            'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
            'rebuild' => $role === Role::Admin ? ['label' => __('Rebuild'), 'icon' => 'refresh'] : null,
            'purge' => $web->canPurgeCache() ? ['label' => __('Purge Nginx Cache'), 'icon' => 'layers'] : null,
            'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d web domains? Their files are removed.')],
        ]);

        return view('web.index', [
            'records' => $records,
            'abilities' => $abilities,
            'showOwner' => $allUsers,
            'owners' => $owners,
            'owner' => $owner,
            'ips' => $web->systemIps(),
            'systems' => $web->systems(),
            'canPurge' => $web->canPurgeCache(),
            'canCreate' => Capabilities::allows($role, 'web.create') && ! $principal->isReadOnly(),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => $bulkActions,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope, WebDomainService $web, UserDirectory $directory): View
    {
        $owner = $this->owner($request->query('owner'), $principal, $scope, $directory);
        abort_unless($policy->create($principal, $owner), 403);

        $userRecord = $directory->all()[$owner] ?? [];
        $canTemplates = $policy->updateTemplates($principal, $owner);

        return view('web.create', [
            'owner' => $owner,
            'owners' => $scope->visibleUsers() === null && Capabilities::allows($principal->actingRole(), 'users.view') ? $directory->names() : null,
            'ips' => $this->ipOptions($web->userIps($owner)),
            'systems' => $web->systems(),
            'canTemplates' => $canTemplates,
            'templates' => $canTemplates ? $web->webTemplates() : [],
            'backendTemplates' => $canTemplates ? $web->backendTemplates() : [],
            'proxyTemplates' => $canTemplates ? $web->proxyTemplates() : [],
            'defaults' => [
                'template' => $userRecord['WEB_TEMPLATE'] ?? 'default',
                'backend_template' => $userRecord['BACKEND_TEMPLATE'] ?? 'default',
                'proxy_template' => $userRecord['PROXY_TEMPLATE'] ?? 'default',
            ],
        ]);
    }

    public function store(StoreWebDomainRequest $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope, WebDomainService $web, UserDirectory $directory, AdvancedTab $advanced): RedirectResponse
    {
        $owner = $this->owner($request->input('owner'), $principal, $scope, $directory);
        abort_unless($policy->create($principal, $owner), 403);

        $userIps = $web->userIps($owner);
        $ip = (string) $request->input('ip');
        if (! array_key_exists($ip, $userIps)) {
            throw ValidationException::withMessages(['ip' => __('Please select a valid IP address.')]);
        }

        $domain = (string) $request->input('domain');
        $steps = $web->planCreate($owner, $domain, $ip, $request->boolean('dns'), $request->boolean('mail'), $userIps);
        $aliases = Input::list($request->input('aliases'));
        if ($aliases !== []) {
            // v-add-web-domain [ALIASES] replaces the default www alias.
            $steps[0] = new Step($steps[0]->label, 'v-add-web-domain', [$owner, $domain, $ip, 'yes', implode(',', $aliases)]);
        }

        $result = $web->apply(array_slice($steps, 0, 1));
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->error);
        }
        $result = $web->apply(array_slice($steps, 1));
        if (! $result->ok()) {
            return redirect()->route('web.domains.edit', ['user' => $owner, 'domain' => $domain])
                ->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error);
        }

        // Template choices of an administrator (or with POLICY_USER_EDIT_WEB_TEMPLATES)
        if ($policy->updateTemplates($principal, $owner)) {
            $record = $web->find($owner, $domain);
            $templateSteps = $advanced->plan($record, [
                'template' => $request->input('template'),
                'backend_template' => $request->input('backend_template'),
                'proxy' => $record->hasProxy(),
                'proxy_template' => $request->input('proxy_template'),
                'proxy_extensions' => $request->filled('proxy_extensions') ? $request->input('proxy_extensions') : implode(',', $record->proxyExtensions),
                'fastcgi_cache' => $record->fastcgiCache,
                'fastcgi_duration' => $record->fastcgiDuration,
            ], $web->systems());
            $result = $web->apply($templateSteps);
            if (! $result->ok()) {
                return redirect()->route('web.domains.edit', ['user' => $owner, 'domain' => $domain])
                    ->with('error', sprintf(__('%s failed'), $result->failed->label))->with('error_detail', $result->error);
            }
        }

        return redirect()->route('web.domains.edit', ['user' => $owner, 'domain' => $domain])
            ->with('ok', sprintf(__('Domain %s has been created successfully.'), $domain));
    }

    /** The owner of a new domain: an administrator may pick any user, everyone else gets themselves. */
    private function owner(mixed $requested, Principal $principal, OwnerScope $scope, UserDirectory $directory): string
    {
        $owner = is_string($requested) && $requested !== '' ? $requested : $principal->effectiveUser();
        $scope->assertVisible($owner);
        abort_unless($owner === $principal->effectiveUser() || in_array($owner, $directory->names(), true), 404);

        return $owner;
    }

    /**
     * @param  array<string, array<string, string>>  $ips
     * @return array<string, string> ip => label (with the NAT address)
     */
    private function ipOptions(array $ips): array
    {
        $options = [];
        foreach ($ips as $ip => $row) {
            $nat = trim((string) ($row['NAT'] ?? ''));
            $options[$ip] = $nat !== '' ? "{$ip} ({$nat})" : $ip;
        }

        return $options;
    }
}
