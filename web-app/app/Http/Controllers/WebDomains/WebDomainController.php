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
use App\Services\WebDomains\Tabs\TemplateVarsTab;
use App\Services\WebDomains\TemplateCatalog;
use App\Services\WebDomains\TemplateForm;
use App\Services\WebDomains\WebDomainActions;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web domain list and creation (web/list/web, web/add/web).
 */
final class WebDomainController extends Controller
{
    /** Lists longer than this are paged on the server. */
    public const PAGE_SIZE = 500;

    public function index(Request $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope, WebDomainService $web, UserDirectory $directory, TemplateCatalog $catalog): View
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
            // Administrators and resellers (their customers' domains too)
            'showOwner' => count($owners) > 1 || $allUsers,
            'everyUser' => $allUsers,
            'owners' => $owners,
            'owner' => $owner,
            'ips' => $web->systemIps(),
            'systems' => $web->systems(),
            // Template titles of the metadata (E6), the name when there is none
            'templateTitles' => [
                'web' => array_map(fn ($meta) => $meta->hasMeta ? __($meta->title) : $meta->name, $catalog->templates('web')),
                'proxy' => array_map(fn ($meta) => $meta->hasMeta ? __($meta->title) : $meta->name, $catalog->templates('proxy')),
            ],
            'canPurge' => $web->canPurgeCache(),
            'canCreate' => Capabilities::allows($role, 'web.create') && ! $principal->isReadOnly(),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => $bulkActions,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope, WebDomainService $web, UserDirectory $directory, TemplateForm $form, WebDomainActions $actions): View
    {
        $owner = $this->owner($request->query('owner'), $principal, $scope, $directory);
        abort_unless($policy->create($principal, $owner), 403);

        $userRecord = $directory->all()[$owner] ?? [];
        $canTemplates = $policy->updateTemplates($principal, $owner);

        $defaults = [
            'template' => $userRecord['WEB_TEMPLATE'] ?? 'default',
            'backend_template' => $userRecord['BACKEND_TEMPLATE'] ?? 'default',
            'proxy_template' => $userRecord['PROXY_TEMPLATE'] ?? 'default',
        ];

        return view('web.create', [
            'owner' => $owner,
            'owners' => Capabilities::allows($principal->actingRole(), 'users.view') ? ($scope->visibleUsers() ?? $directory->names()) : null,
            'ips' => $this->ipOptions($web->userIps($owner)),
            'systems' => $web->systems(),
            'canTemplates' => $canTemplates,
            'backendTemplates' => $canTemplates ? $actions->backendTemplatesFor($principal, $owner) : [],
            'defaults' => $defaults,
            ...($canTemplates ? $form->data($owner, '', $defaults['template'], $defaults['proxy_template']) : []),
        ]);
    }

    public function store(StoreWebDomainRequest $request, Principal $principal, WebDomainPolicy $policy, OwnerScope $scope, WebDomainService $web, UserDirectory $directory, WebDomainActions $actions): RedirectResponse
    {
        $owner = $this->owner($request->input('owner'), $principal, $scope, $directory);
        abort_unless($policy->create($principal, $owner), 403);

        $ip = (string) $request->input('ip');
        $userIps = $actions->checkIp($owner, $ip);

        $domain = (string) $request->input('domain');
        $canTemplates = $policy->updateTemplates($principal, $owner);

        // The backend port of a template that takes one is checked before the
        // domain exists (E6 spec §7.2).
        $port = (string) $request->input('backend_port', '');
        if ($canTemplates) {
            $actions->checkPort($owner, $domain, null, ['template' => $request->input('template'), 'proxy_template' => $request->input('proxy_template')], $port);
            // A PHP version of the package only (E41 §7.5)
            $actions->checkBackendTemplate($principal, $owner, $request->input('backend_template'));
        }

        $steps = $actions->planCreate($owner, $domain, $ip, $request->boolean('dns'), $request->boolean('mail'), Input::list($request->input('aliases')), $userIps);

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
        if ($canTemplates) {
            $record = $web->find($owner, $domain);
            $result = $web->apply($actions->planCreatedTemplates($record, $request->only(['template', 'backend_template', 'proxy_template', 'proxy_extensions']), $port));
            if (! $result->ok() && $result->failed?->command === TemplateVarsTab::COMMAND) {
                return redirect()->to(route('web.domains.edit', ['user' => $owner, 'domain' => $domain]).'#advanced')
                    ->withErrors(['backend_port' => TemplateVarsTab::error($result->exitCode, $result->error, $owner)], 'advanced')
                    ->with('error', sprintf(__('%s failed'), $result->failed->label));
            }
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
