<?php

namespace App\Http\Controllers\Packages;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packages\PackageRequest;
use App\Policies\PackagePolicy;
use App\Services\Packages\PackageRecord;
use App\Services\Packages\PackageService;
use App\Services\Users\UserService;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Packages (web/list/package, web/add/package, web/edit/package) with the
 * reseller settings of E11 spec §8.
 */
final class PackageController extends Controller
{
    public function __construct(
        private readonly PackageService $packages,
        private readonly UserService $users,
    ) {}

    public function index(Principal $principal, PackagePolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);
        $isReseller = $principal->actingRole() === Role::Reseller;
        $records = $this->visible($principal);

        $abilities = [];
        foreach ($records as $name => $record) {
            $abilities[$name] = $policy->abilities($principal, $record);
        }

        return view('packages.index', [
            'records' => $records,
            'abilities' => $abilities,
            'isReseller' => $isReseller,
            'reseller' => $isReseller ? $principal->effectiveUser() : '',
            'canCreate' => $policy->create($principal),
            'showResellerColumns' => $policy->manageResellerSettings($principal),
        ]);
    }

    public function create(Principal $principal, PackagePolicy $policy): View
    {
        abort_unless($policy->create($principal), 403);

        return $this->form($principal, $policy, null);
    }

    public function store(PackageRequest $request, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        abort_unless($policy->create($principal), 403);
        $reseller = $this->resellerOf($principal);
        $name = (string) $request->input('name');
        $stored = $reseller !== '' ? $reseller.'_'.$name : $name;
        if ($this->packages->exists($stored)) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }
        $values = $this->checkedValues($request, [], $reseller);
        $tenancy = $policy->manageResellerSettings($principal) ? $request->tenancyValues() : null;

        $result = $this->users->apply($this->packages->planCreate($name, $values, $tenancy, $reseller, $this->checkedPorts($request, $reseller),
            $this->checkedNode($request, $reseller), $this->checkedDocker($request, $reseller)));
        if (! $result->ok()) {
            return $this->failed($request, $result);
        }

        return redirect()->route('packages.index')->with('ok', __('Package has been created.'));
    }

    public function edit(string $package, Principal $principal, PackagePolicy $policy): View
    {
        $record = $this->visibleOr404($principal, $package);
        abort_unless($policy->update($principal, $record), 403);

        return $this->form($principal, $policy, $record);
    }

    public function update(PackageRequest $request, string $package, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        $record = $this->visibleOr404($principal, $package);
        abort_unless($policy->update($principal, $record), 403);
        $reseller = $this->resellerOf($principal);

        $newName = $reseller !== '' ? $record->name : (string) $request->input('name');
        if ($newName !== $record->name && $this->packages->exists($newName)) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }
        $values = $this->checkedValues($request, $record->data, $reseller);
        $tenancy = $policy->manageResellerSettings($principal) ? $request->tenancyValues() : null;

        $result = $this->users->apply($this->packages->planUpdate($record, $newName, $values, $tenancy, $reseller, $this->checkedPorts($request, $reseller),
            $this->checkedNode($request, $reseller), $this->checkedDocker($request, $reseller)));
        if (! $result->ok()) {
            return $this->failed($request, $result);
        }

        return redirect()->route('packages.edit', ['package' => $newName])->with('ok', __('Changes have been saved.'));
    }

    public function copy(Request $request, string $package, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        $record = $this->visibleOr404($principal, $package);
        abort_unless($policy->abilities($principal, $record)['copy'], 403);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:60', 'regex:/^[[:alnum:]][-.\w]*$/']])['name'];
        if ($this->packages->exists($name)) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }

        $result = $this->users->apply($this->packages->planCopy($record, $name));

        return $result->ok()
            ? redirect()->route('packages.edit', ['package' => $name])->with('ok', __('Package has been copied.'))
            : back()->with('error', $result->error);
    }

    public function destroy(string $package, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        $record = $this->visibleOr404($principal, $package);
        abort_unless($policy->delete($principal, $record), 403);
        if ($record->users > 0) {
            return back()->with('error', __('This package is used by users and cannot be deleted.'));
        }

        $result = $this->users->apply($this->packages->planDelete($record, $this->resellerOf($principal)));

        return $result->ok()
            ? redirect()->route('packages.index')->with('ok', __('Package has been deleted.'))
            : back()->with('error', $result->error);
    }

    private function form(Principal $principal, PackagePolicy $policy, ?PackageRecord $record): View
    {
        $reseller = $this->resellerOf($principal);
        $defaults = [
            'WEB_TEMPLATE' => 'default', 'BACKEND_TEMPLATE' => 'default', 'PROXY_TEMPLATE' => 'default', 'DNS_TEMPLATE' => 'default',
            'WEB_DOMAINS' => '1', 'WEB_ALIASES' => '5', 'DNS_DOMAINS' => '1', 'DNS_RECORDS' => 'unlimited', 'MAIL_DOMAINS' => '1',
            'MAIL_ACCOUNTS' => '5', 'DATABASES' => '1', 'CRON_JOBS' => '1', 'DISK_QUOTA' => '1000', 'BANDWIDTH' => '1000',
            'CPU_QUOTA' => 'unlimited', 'CPU_QUOTA_PERIOD' => 'unlimited', 'MEMORY_LIMIT' => 'unlimited', 'SWAP_LIMIT' => 'unlimited',
            'RATE_LIMIT' => '200', 'BACKUPS' => '1', 'BACKUPS_INCREMENTAL' => 'no', 'SHELL' => 'nologin',
            'NS' => (string) ($this->packages->all()['default']?->get('NS') ?? ''),
            'USERS' => '0', 'RESELLER_PACKAGES' => '', 'RESELLER_VISIBLE' => 'no', 'PORTS' => '0',
            'NODE' => 'no', 'NODE_VERSIONS' => '3', 'NODE_ALLOWED' => 'lts', 'NODE_APPS' => '3',
            'DOCKER' => 'no', 'DOCKER_APPS' => '0', 'DOCKER_CPUS' => 'unlimited', 'DOCKER_MEMORY' => 'unlimited',
        ];

        return view('packages.form', [
            'record' => $record,
            'values' => array_merge($defaults, $record?->data ?? []),
            'name' => $record === null ? '' : UserService::packageLabel($record->name, $reseller),
            'canRename' => $reseller === '',
            'templates' => $this->packages->templates(),
            'resources' => $this->packages->resourcesLimit(),
            'resellerSettings' => $policy->manageResellerSettings($principal),
            'ownLimits' => $reseller !== '' ? $this->users->find($reseller)->data : [],
            'ownPorts' => $reseller !== '' ? $this->ownPorts($reseller) : null,
            'ownNode' => $reseller !== '' ? $this->ownNode($reseller) : null,
            'ownDocker' => $reseller !== '' ? $this->ownDocker($reseller) : null,
        ]);
    }

    /** @return array<string, PackageRecord> what the principal may see */
    private function visible(Principal $principal): array
    {
        $all = $this->packages->all();
        if ($principal->actingRole() === Role::Admin) {
            return $all;
        }

        return array_intersect_key($all, array_flip($this->packages->assignableBy($principal->effectiveUser())));
    }

    private function visibleOr404(Principal $principal, string $package): PackageRecord
    {
        return $this->visible($principal)[$package] ?? abort(404);
    }

    private function resellerOf(Principal $principal): string
    {
        return $principal->actingRole() === Role::Reseller ? $principal->effectiveUser() : '';
    }

    /**
     * The values of the form, with templates and shell from the server's
     * lists; a reseller may not raise a limit above its own.
     *
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    private function checkedValues(PackageRequest $request, array $current, string $reseller): array
    {
        $values = $request->packageValues($current, $this->packages->resourcesLimit());
        $templates = $this->packages->templates();
        $errors = [];
        foreach (['WEB_TEMPLATE' => 'web', 'BACKEND_TEMPLATE' => 'backend', 'PROXY_TEMPLATE' => 'proxy', 'DNS_TEMPLATE' => 'dns', 'SHELL' => 'shells'] as $key => $list) {
            if ($templates[$list] !== [] && ! in_array($values[$key], $templates[$list], true) && ($current[$key] ?? null) !== $values[$key]) {
                $errors[strtolower($key === 'SHELL' ? 'shell' : $key)] = __('Please select a valid value.');
            }
        }
        if (count($request->nameservers()) < 2) {
            $errors['nameservers'] = __('Enter at least two name servers.');
        }
        if ($reseller !== '') {
            $own = $this->users->find($reseller);
            foreach ([...PackageRecord::COUNTED, 'RATE_LIMIT', 'BACKUPS'] as $key) {
                $limit = $own->get($key, 'unlimited');
                if ($limit === 'unlimited' || $limit === '') {
                    continue;
                }
                if ($values[$key] === 'unlimited' || (int) $values[$key] > (int) $limit) {
                    $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $limit);
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** PORTS of the reseller's own package (E12). */
    private function ownPorts(string $reseller): string
    {
        $package = $this->users->find($reseller)->package();

        return ($this->packages->all()[$package] ?? null)?->get('PORTS', '0') ?? '0';
    }

    /**
     * Node.js keys of the reseller's own package (E13, E14).
     *
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_APPS: string}
     */
    private function ownNode(string $reseller): array
    {
        $record = $this->packages->all()[$this->users->find($reseller)->package()] ?? null;

        return [
            'NODE' => $record?->get('NODE', 'no') ?? 'no',
            'NODE_VERSIONS' => $record?->get('NODE_VERSIONS', '3') ?? '3',
            'NODE_APPS' => $record?->get('NODE_APPS', '3') ?? '3',
        ];
    }

    /**
     * Node.js keys of the form; a reseller cannot give Node.js it does not
     * have, nor more runtimes or apps than its own package allows.
     *
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}
     */
    private function checkedNode(PackageRequest $request, string $reseller): array
    {
        $node = $request->nodeValues();
        if ($reseller !== '') {
            $own = $this->ownNode($reseller);
            $errors = [];
            if ($node['NODE'] === 'yes' && $own['NODE'] !== 'yes') {
                $errors['node'] = __('Node.js is not included in your plan.');
            }
            foreach (['NODE_VERSIONS' => 'node_versions', 'NODE_APPS' => 'node_apps'] as $key => $field) {
                $limit = $own[$key];
                if ($limit !== 'unlimited' && ($node[$key] === 'unlimited' || (int) $node[$key] > (int) $limit)) {
                    $errors[$field] = sprintf(__('At most %s on your plan.'), $limit);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $node;
    }

    /**
     * Docker app keys of the reseller's own package (E4).
     *
     * @return array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}
     */
    private function ownDocker(string $reseller): array
    {
        $record = $this->packages->all()[$this->users->find($reseller)->package()] ?? null;

        return [
            'DOCKER' => $record?->get('DOCKER', 'no') ?? 'no',
            'DOCKER_APPS' => $record?->get('DOCKER_APPS', '0') ?? '0',
            'DOCKER_CPUS' => $record?->get('DOCKER_CPUS', 'unlimited') ?? 'unlimited',
            'DOCKER_MEMORY' => $record?->get('DOCKER_MEMORY', 'unlimited') ?? 'unlimited',
        ];
    }

    /**
     * Docker app keys of the form; a reseller cannot give Docker apps it
     * does not have, nor more apps, CPUs or memory than its own package.
     *
     * @return array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}
     */
    private function checkedDocker(PackageRequest $request, string $reseller): array
    {
        $docker = $request->dockerValues();
        if ($reseller !== '') {
            $own = $this->ownDocker($reseller);
            $errors = [];
            if ($docker['DOCKER'] === 'yes' && $own['DOCKER'] !== 'yes') {
                $errors['docker'] = __('Docker apps are not included in your plan.');
            }
            $mb = fn (string $size) => preg_match('/^(\d+)([MG]?)$/', $size, $m) === 1 ? (int) $m[1] * ($m[2] === 'G' ? 1024 : 1) : PHP_INT_MAX;
            $above = [
                'DOCKER_APPS' => fn (string $value, string $limit) => $value === 'unlimited' || (int) $value > (int) $limit,
                'DOCKER_CPUS' => fn (string $value, string $limit) => $value === 'unlimited' || (float) $value > (float) $limit,
                'DOCKER_MEMORY' => fn (string $value, string $limit) => $mb($value) > $mb($limit),
            ];
            foreach ($above as $key => $check) {
                if ($own[$key] !== 'unlimited' && $check($docker[$key], $own[$key])) {
                    $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $own[$key]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $docker;
    }

    /** PORTS of the form; a reseller may not go above its own. */
    private function checkedPorts(PackageRequest $request, string $reseller): string
    {
        $ports = $request->portsValue();
        if ($reseller !== '') {
            $limit = $this->ownPorts($reseller);
            if ($limit !== 'unlimited' && ($ports === 'unlimited' || (int) $ports > (int) $limit)) {
                throw ValidationException::withMessages(['ports' => sprintf(__('At most %s on your plan.'), $limit)]);
            }
        }

        return $ports;
    }

    private function failed(PackageRequest $request, ApplyResult $result): RedirectResponse
    {
        /** @var Step $failed */
        $failed = $result->failed;

        return back()->withInput()
            ->with('error', sprintf(__('%s failed'), $failed->label))
            ->with('error_detail', $result->error);
    }
}
