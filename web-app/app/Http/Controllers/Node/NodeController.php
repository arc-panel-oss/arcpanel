<?php

namespace App\Http\Controllers\Node;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\NodePolicy;
use App\Services\Node\NodeService;
use App\View\Navigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Node.js page of an account (E13 spec §7) and the JSON routes of its
 * Vue island. Routes with {user} run through `effective-user` (404 outside
 * the scope); long operations start a background task the island polls.
 */
final class NodeController extends Controller
{
    /** Strings of the island, translated for the page */
    private const STRINGS = [
        'Node.js', 'Runtimes', 'Global packages', 'Install a version', 'Install', 'Cancel', 'Close',
        'Enable Node.js', 'Remove Node.js', 'Make default', 'Uninstall', 'Default', 'Version', 'Status', 'Size', 'Packages',
        'Package', 'Latest', 'Add', 'Remove', 'Check for updates', 'Update all', 'Last checked: %s', 'Not checked yet',
        'Comes with Node.js', 'Update available', 'Up to date', 'Package name', 'e.g. pm2 or @nestjs/cli@11',
        'Quick add', 'Active LTS', 'Maintenance LTS', 'Current', 'End of life', 'End of life on %s', 'LTS',
        'Allowed by your package', 'Not allowed by your package', '%d of %s runtimes', 'unlimited', 'Allowed majors: %s',
        'Node.js is not included in your package.', 'Node.js is not enabled yet.',
        'Enable Node.js to install runtimes and global packages. Your shell (SSH, Web Terminal) then has node, npm and nvm.',
        'No runtime is installed yet.', 'Your package allows no more runtimes.',
        'Task', 'Running', 'Done', 'Failed', 'Show the output', 'Hide the output', 'Another task is running.',
        'Install Node.js %s', 'Install %s', 'Update the packages of Node.js %s', 'Check the packages of Node.js %s for updates',
        'Remove Node.js and every runtime and package? This cannot be undone.', 'Uninstall Node.js %s and its global packages?',
        'Remove %s?', 'Open terminal', 'Read-only: changes are disabled.', 'Something went wrong', 'Loading…',
        'The default runtime is used by your shell and by new apps.', 'Changes have been saved.', 'Select a runtime',
        'MB', 'Runtime', 'Installed',
    ];

    public function __construct(private readonly NodeService $node) {}

    /** /node: the effective account's page. */
    public function index(Principal $principal): RedirectResponse
    {
        return redirect()->route('node.show', ['user' => $principal->effectiveUser()]);
    }

    public function show(string $user, Principal $principal, NodePolicy $policy, SysConfig $config): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $account = $this->node->account($user);

        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }
        $terminal = $user === $principal->effectiveUser() && $config->get('WEB_TERMINAL') === 'true' && $principal->loginShell !== 'nologin'
            ? Navigation::url('terminal.index') : null;

        return view('node.index', [
            'user' => $user,
            'props' => [
                'user' => $user,
                'base' => route('node.show', ['user' => $user]).'/api',
                'account' => $account->toArray(),
                'task' => $this->node->task($user)->toArray(),
                'abilities' => $policy->abilities($principal, $user),
                'readOnly' => $principal->isReadOnly(),
                'terminal' => $terminal,
                'strings' => $strings,
            ],
        ]);
    }

    public function overview(string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json([
            'account' => $this->node->account($user)->toArray(),
            'task' => $this->node->task($user)->toArray(),
            'abilities' => $policy->abilities($principal, $user),
        ]);
    }

    public function releases(string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json(['releases' => $this->node->releases($this->node->account($user))]);
    }

    public function packages(string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json($this->node->packages($user, $version));
    }

    public function task(Request $request, string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $request->validate(['lines' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $lines = $request->integer('lines') ?: 100;

        return response()->json(['task' => $this->node->task($user, $lines)->toArray()]);
    }

    public function enable(string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $this->node->enable($user);

        return $this->overview($user, $principal, $policy);
    }

    public function disable(string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->remove($principal, $user), 403);
        $this->node->disable($user);

        return $this->overview($user, $principal, $policy);
    }

    public function install(Request $request, string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $spec = (string) $request->validate(['spec' => ['required', 'string', 'regex:'.NodeService::SPEC]])['spec'];

        return $this->started($user, $this->node->startTask($user, 'add-version', [$spec]));
    }

    public function setDefault(string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $this->node->setDefault($user, $version);

        return $this->overview($user, $principal, $policy);
    }

    public function destroyVersion(string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->remove($principal, $user), 403);
        $this->node->deleteVersion($user, $version);

        return $this->overview($user, $principal, $policy);
    }

    public function addPackage(Request $request, string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $spec = (string) $request->validate(['spec' => ['required', 'string', 'max:280', 'regex:'.NodeService::PACKAGE_SPEC]])['spec'];

        return $this->started($user, $this->node->startTask($user, 'add-package', [$version, $spec]));
    }

    public function updatePackages(string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);

        return $this->started($user, $this->node->startTask($user, 'update-packages', [$version]));
    }

    public function checkOutdated(string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);

        return $this->started($user, $this->node->startTask($user, 'check-outdated', [$version]));
    }

    public function destroyPackage(string $user, string $version, string $name, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->remove($principal, $user), 403);
        $this->node->deletePackage($user, $version, $name);

        return response()->json($this->node->packages($user, $version));
    }

    private function started(string $user, string $id): JsonResponse
    {
        return response()->json(['id' => $id, 'task' => $this->node->task($user)->toArray()], 202);
    }
}
