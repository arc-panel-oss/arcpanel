<?php

namespace App\Http\Controllers\AppProcesses;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\AppProcessPolicy;
use App\Policies\UserSecretPolicy;
use App\Services\AppProcesses\AppProcessService;
use App\Services\Keys\UserSecretManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The app processes pages of an account (E42 spec §9): the list, the
 * create wizard and the page of one process. Each renders its content on
 * the server too and mounts the app-processes island, which uses
 * AppProcessController.
 */
final class AppProcessPageController extends Controller
{
    /** Strings of the island, translated for the page */
    private const STRINGS = [
        '%d of %s processes',
        '%d running · %d stopped · %d failed',
        '%s is set by the panel.',
        '%s runs.',
        'A task runs once as your user in the working directory of the process; after an install the process is reloaded.',
        'A value can reference a secret of the account as %s; the app gets its value when it starts.',
        'A value fits on one line.',
        'Actions',
        'Add a variable',
        'Add a web domain first; a process runs for one domain.',
        'Add process',
        'All domains',
        'All statuses',
        'An app process keeps a server or a worker of your domain running: Laravel Octane, a queue worker, Gunicorn, Puma or your own command.',
        'App processes',
        'App processes are not included in your package.',
        'Back',
        'Build',
        'CPU time',
        'CPUs',
        'Cancel',
        'Clear the view',
        'Command',
        'Continue',
        'Created',
        'Custom command',
        'Danger zone',
        'Delete',
        'Delete the app process %s?',
        'Delete the process',
        'Detect',
        'Domain',
        'Done',
        'Download',
        'Environment',
        'Errors',
        'Failed',
        'Found in %s',
        'Hide %s',
        'Insert a secret',
        'Install',
        'Key',
        'Kind',
        'Last task',
        'Letters, digits and _, not starting with a digit.',
        'Limits',
        'Loading…',
        'Log',
        'Logs',
        'Lower-case letters, digits and -, starting with a letter.',
        'MB',
        'Memory',
        'Memory (MB)',
        'Name',
        'No app process yet.',
        'No process matches the filters.',
        'No variable yet.',
        'Not running',
        'Nothing logged yet.',
        'Open',
        'Output',
        'Overview',
        'PID',
        'Pause',
        'Port',
        'Preset',
        'Process',
        'Read-only: changes are disabled.',
        'Relative to the folder of the domain, e.g. public_html or app.',
        'Reload',
        'Remove %s',
        'Resources',
        'Restart',
        'Restarts',
        'Resume',
        'Run',
        'Running',
        'Runs as your user in the working directory, without a shell: quotes group words, nothing is expanded. %port% is the port of a web process.',
        'Save',
        'Save and restart',
        'Saved; the app was restarted.',
        'Saved; the process was restarted when it ran.',
        'Settings',
        'Show %s',
        'Start',
        'Started',
        'Starting',
        'Status',
        'Step %d of %d',
        'Stop',
        'Stopped',
        'Stopping',
        'Such as 1 or 0.5 (at most %s).',
        'Suspended',
        'Task',
        'Tasks',
        'Template',
        'The domain passes its requests to the port of the process through this template.',
        'The output of the last task is in the Task stream of the logs.',
        'The process is restarted when it uses more (at most %d MB).',
        'The process stopped after too many restarts; read its log, then start it again.',
        'The process stops and is removed; a web process frees its port and the domain gets its previous template back. Your code, the environment file and the logs stay.',
        'This domain has a web process already.',
        'This key is set twice.',
        'This process is suspended.',
        'Uptime',
        'Value',
        'Value of %s',
        'Variables of the .env file of the app. PORT, HOST and NODE_ENV are set by the panel.',
        'Variables of the process. PORT and HOST of a web process, PATH, HOME, USER and LOGNAME are set by the panel.',
        'Web domain',
        'Web process',
        'Worker',
        'Working directory',
        'Your package allows no more processes.',
        'a',
        'bundle install',
        'composer install',
        'exit %s',
        'php artisan migrate',
        'pip install -r requirements.txt',
        'reload',
        'restart',
        'runs in the background without a port, such as a queue worker',
        'serves the domain on a port of your pool; the domain gets the template of the preset',
        'start',
        'stop',
        'unlimited',
        'web · port %s',
        'worker',
    ];

    public function __construct(
        private readonly AppProcessService $processes,
    ) {}

    /** /app-processes: the list of the effective account. */
    public function entry(Principal $principal): RedirectResponse
    {
        return redirect()->route('app-processes.index', ['user' => $principal->effectiveUser()]);
    }

    public function index(string $user, Principal $principal, AppProcessPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);

        return view('app-processes.index', [
            'user' => $user,
            'props' => [
                ...$this->common($user, $principal, $policy, 'list'),
                'account' => $this->processes->processes($user)->toArray(),
            ],
        ]);
    }

    public function create(Request $request, string $user, Principal $principal, AppProcessPolicy $policy): View
    {
        abort_unless($policy->manage($principal, $user), 403);
        $domain = $request->query('domain');

        return view('app-processes.create', [
            'user' => $user,
            'props' => [
                ...$this->common($user, $principal, $policy, 'create'),
                'domain' => is_string($domain) && preg_match('/^[^\s\/]{1,253}$/', $domain) === 1 ? strtolower($domain) : '',
            ],
        ]);
    }

    public function show(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy, UserSecretManager $secrets, UserSecretPolicy $secretPolicy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $process = $this->processes->show($user, $domain, $name)->toArray();

        return view('app-processes.show', [
            'user' => $user,
            'process' => $process,
            'props' => [
                ...$this->common($user, $principal, $policy, 'show'),
                'process' => $process,
                // Names for the reference picker of the environment (E38)
                'secrets' => $secretPolicy->manage($principal, $user) ? $secrets->names($user) : [],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function common(string $user, Principal $principal, AppProcessPolicy $policy, string $view): array
    {
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }

        return [
            'view' => $view,
            'user' => $user,
            'base' => route('app-processes.api.index', ['user' => $user]),
            'pages' => [
                'list' => route('app-processes.index', ['user' => $user]),
                'create' => route('app-processes.create', ['user' => $user]),
                'show' => route('app-processes.show', ['user' => $user, 'domain' => '__DOMAIN__', 'name' => '__NAME__']),
            ],
            'abilities' => $policy->abilities($principal, $user),
            'task' => $this->processes->task($user)?->toArray(),
            'readOnly' => $principal->isReadOnly(),
            'strings' => $strings,
        ];
    }
}
