<?php

namespace App\Http\Controllers\Node;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FilePolicy;
use App\Policies\NodePolicy;
use App\Policies\UserSecretPolicy;
use App\Services\Keys\UserSecretManager;
use App\Services\Node\NodeAppService;
use App\Services\Node\NodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Node apps pages of an account (E14 spec §7): the list, the create
 * wizard and the page of one app. Each renders its content on the server
 * too and mounts the node-apps island, which uses NodeAppController.
 */
final class NodeAppPageController extends Controller
{
    /** Strings of the island, translated for the page */
    private const STRINGS = [
        'A value can reference a secret of the account as %s; the app gets its value when it starts.', 'Insert a secret',
        '%d of %s apps', '%d online · %d stopped · %d errored', '%d selected', '%s is set by the panel.', 'A file', 'A port of my pool',
        'A script of package.json', 'A value fits on one line.', 'Actions', 'Add a variable', 'Add a web domain first; an app runs for one domain.',
        'An app runs a Node.js server for one of your web domains, with its own port, logs and restart on failure.', 'App',
        'Automatic (a free port of your pool)', 'Back', 'Build', 'Build %s', 'CPU', 'Cancel', 'Changes have been saved.',
        'Check the packages of Node.js %s for updates', 'Clear the view', 'Continue', 'Create app', 'Created', 'Danger zone', 'Default (%s)',
        'Delete', 'Delete the Node.js app of %s?', 'Delete the app', 'Domain', 'Done', 'Download', 'Environment', 'Errored', 'Errors',
        'Every web domain has an app already.', 'Failed', 'Hide %s', 'Hide the output', 'Install', 'Install %s', 'Install Node.js %s',
        'Install a Node.js version first.', 'Install dependencies', 'Install the dependencies of %s', 'Instances', 'Keep .env and the logs', 'Key',
        'Letters, digits and _, not starting with a digit.', 'Loading…', 'Log', 'Logs', 'MB', 'Memory',
        'More than one runs the app in cluster mode (at most %d).',
        'Next, install the dependencies of package.json and build the app if it needs a build.',
        'Next.js and Nuxt start with the start script (next start, nuxt start).', 'No Node.js app yet.',
        'No package.json in this folder yet; you can upload the code later and start the app then.', 'No variable yet.', 'Node.js apps',
        'Node.js is not included in your package.', 'Node.js version', 'Not running', 'Nothing logged yet.', 'Online', 'Open', 'Open %s',
        'Open the Node.js page', 'Open app folder', 'Open the app', 'Open the site', 'Output', 'Overview', 'Pause', 'Port', 'Port and name', 'Port number',
        'Process name', 'Read package.json', 'Read-only: changes are disabled.', 'Relative to the folder of the domain, e.g. public_html or app.',
        'Reload', 'Remove %s', 'Resources', 'Restart', 'Restart above', 'Restart above (MB)', 'Restarts', 'Resume', 'Run build', 'Running', 'Runtime',
        'Save', 'Save and restart', 'Saved; the app was restarted.', 'Script', 'Select %s', 'Select all', 'Settings', 'Show %s', 'Show the output',
        'Something went wrong', 'Start', 'Start command', 'Start file', 'Start with', 'Starting', 'Status', 'Step %d of %d', 'Stop', 'Stopped',
        'Suspended', 'Task', 'Template', 'The app of %s runs.',
        'The app stops, its port is freed and the domain gets its previous template back. Your code stays.',
        'The domain gets a Node.js template that passes its requests to the app.', 'The name in pm2: lower-case letters, digits, dots, - and _.',
        'This app is suspended.', 'This key is set twice.', 'Update the packages of Node.js %s', 'Uptime', 'Value', 'Value of %s',
        'Variables of the .env file of the app. PORT, HOST and NODE_ENV are set by the panel.', 'Web domain', 'Working directory',
        'Your package allows no more apps.', 'automatic', 'cluster mode', 'default', 'follows the default runtime of the account',
        'package.json has no build script.', 'pm2 restarts the app when it uses more memory (at most %d MB).', 'unlimited',
    ];

    public function __construct(
        private readonly NodeAppService $apps,
        private readonly NodeService $node,
        private readonly FilePolicy $files,
        private readonly SysConfig $config,
    ) {}

    /** /node-apps: the list of the effective account. */
    public function entry(Principal $principal): RedirectResponse
    {
        return redirect()->route('node.apps.index', ['user' => $principal->effectiveUser()]);
    }

    public function index(string $user, Principal $principal, NodePolicy $policy): View
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $apps = $this->apps->apps($user);

        return view('node.apps.index', [
            'user' => $user,
            'props' => [
                ...$this->common($user, $principal, $policy, 'list'),
                'account' => $apps->toArray(),
                'runtimes' => count($this->node->account($user)->runtimes),
            ],
        ]);
    }

    public function create(Request $request, string $user, Principal $principal, NodePolicy $policy): View
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $domain = $request->query('domain');

        return view('node.apps.create', [
            'user' => $user,
            'props' => [
                ...$this->common($user, $principal, $policy, 'create'),
                'domain' => is_string($domain) && preg_match('/^[^\s\/]{1,253}$/', $domain) === 1 ? strtolower($domain) : '',
            ],
        ]);
    }

    public function show(string $user, string $domain, Principal $principal, NodePolicy $policy, UserSecretManager $secrets, UserSecretPolicy $secretPolicy): View
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $app = $this->apps->show($user, $domain);
        $apps = $this->apps->apps($user);
        $account = $this->node->account($user);

        return view('node.apps.show', [
            'user' => $user,
            'app' => $app->toArray(),
            'props' => [
                ...$this->common($user, $principal, $policy, 'show'),
                'app' => $app->toArray(),
                'runtimes' => array_map(fn ($runtime) => $runtime->toArray(), $account->runtimes),
                'defaultRuntime' => $account->default,
                'maxInstances' => $apps->maxInstances,
                'memoryLimit' => $apps->memoryLimit,
                // Names for the reference picker of the environment (E38)
                'secrets' => $secretPolicy->manage($principal, $user) ? $secrets->names($user) : [],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function common(string $user, Principal $principal, NodePolicy $policy, string $view): array
    {
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }

        return [
            'view' => $view,
            'user' => $user,
            'base' => route('node.show', ['user' => $user]).'/api/apps',
            'pages' => [
                'list' => route('node.apps.index', ['user' => $user]),
                'create' => route('node.apps.create', ['user' => $user]),
                'show' => route('node.apps.show', ['user' => $user, 'domain' => '__DOMAIN__']),
                'node' => $policy->view($principal, $user) ? route('node.show', ['user' => $user]) : null,
                // File manager (E3 spec §7.7): the folder of an app
                'files' => $this->config->is('FILE_MANAGER', 'true') && $this->files->view($principal, $user) ? route('files.show', ['user' => $user]) : null,
            ],
            'abilities' => $policy->appAbilities($principal, $user),
            'task' => $this->node->task($user)->toArray(),
            'readOnly' => $principal->isReadOnly(),
            'strings' => $strings,
        ];
    }
}
