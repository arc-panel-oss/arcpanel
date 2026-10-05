<?php

namespace App\Http\Controllers\Docker;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\DockerPolicy;
use App\Policies\FilePolicy;
use App\Policies\PortPolicy;
use App\Services\Docker\DockerAppService;
use App\Services\Docker\DockerCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The Docker apps pages of an account (E4 spec §7): My apps, the store, the
 * detail page of a catalog app with its install form and the page of one
 * app. Each renders its content on the server too and mounts the
 * docker-apps island, which uses DockerAppController.
 */
final class DockerAppPageController extends Controller
{
    /** Strings of the island, translated for the page */
    public const STRINGS = [
        '%d of %s apps', '%d running · %d stopped · %d failed', '%d selected', 'A new version is available.', 'Actions', 'Add a web domain first.',
        'Administrators only', 'All', 'All categories', 'All services', 'An app with the same name exists.', 'App', 'App name', 'Apps',
        'At most 1024 characters, without quotes, $, ` or \\.', 'Back', 'Backups', 'CPU', 'CPUs', 'Cancel', 'Category', 'Changelog',
        'Changes have been saved.', 'Clear the view', 'Container port', 'Containers', 'Danger zone', 'Database dumps', 'Delete', 'Delete the app',
        'Delete the Docker app %s?', 'Description', 'Disk', 'Docker apps', 'Docker apps are not available on this server.',
        'Docker apps are not included in your package.', 'Domain', 'Done', 'Download', 'Every web domain has an app already.', 'Failed',
        'Generate', 'Health', 'Hide', 'Hide the output', 'Homepage', 'Image', 'Immutable: set at installation.', 'Install', 'Install %s',
        'Install the Docker engine', 'Installed', 'Installing', 'Keep the data folder', 'Last dump', 'Latest version', 'License', 'Limits', 'Loading…',
        'Logs', 'MB', 'Memory', 'Memory (MB)', 'Never', 'No app in the store matches.', 'No Docker app yet.', 'Nothing logged yet.', 'Open',
        'Open %s', 'Open data folder', 'Open the store', 'Output', 'Overview', 'Pause', 'Port', 'Ports', 'Private', 'Public',
        'Read-only: changes are disabled.', 'Requirements', 'Restart', 'Resume', 'Roll back', 'Roll back to %s', 'Running', 'Save', 'Search apps',
        'Secret: leave empty to keep the stored value.', 'Select %s', 'Select all', 'Service', 'Services', 'Settings', 'Show', 'Show the output',
        'Snapshot now', 'Something went wrong', 'Start', 'Status', 'Stop', 'Stopped', 'Store', 'Summary', 'Suspended', 'Task', 'The app is ready.',
        'The app stops, its ports are freed and the domain gets its previous template back.', 'The data folder is removed too.',
        'The installation continues in the background.', 'This app has no database to dump: its data folder is backed up as it is.',
        'This app is suspended.', 'Update', 'Update to %s', 'Updates', 'Updating', 'Variables', 'Version', 'Web domain', 'Your package allows no more apps.',
        'degraded', 'exited', 'failed', 'installing', 'missing', 'restarting', 'running', 'stopped', 'suspended', 'unlimited', 'updating',
        'The nightly backup includes the data folder of the app; databases are dumped into data/.dumps 15 minutes before it.',
        'The data of %s is kept for a rollback.', 'Updating stops the app, saves its data and starts the new version; it rolls back by itself when the new version fails.',
        '2 to 32 lower-case letters, digits and dashes, starting with a letter.', 'Ports are private to the server unless made public on the Ports page.',
        'A whole number.', 'true or false.', 'Choose one of the values.', 'At least 8 characters.', 'An email address.',
        'An http or https URL.', 'The value has the wrong format.', 'This value is required.',
        'Uses the server time zone by default.', 'monitoring', 'automation', 'dev', 'cms', 'storage', 'security', 'database', 'analytics', 'other',
    ];

    public function __construct(
        private readonly DockerAppService $apps,
        private readonly DockerCatalog $catalog,
        private readonly FilePolicy $files,
        private readonly PortPolicy $ports,
        private readonly SysConfig $config,
    ) {}

    /** /docker-apps: the list of the effective account. */
    public function entry(Principal $principal): RedirectResponse
    {
        return redirect()->route('docker.apps.index', ['user' => $principal->effectiveUser()]);
    }

    public function index(string $user, Principal $principal, DockerPolicy $policy): View
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $apps = $this->apps->apps($user);

        return view('docker.apps.index', [
            'user' => $user,
            'account' => $apps->toArray(),
            'props' => [...$this->common($user, $principal, $policy, 'list'), 'account' => $apps->toArray()],
        ]);
    }

    public function store(string $user, Principal $principal, DockerPolicy $policy): View
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $admin = $policy->installAdminOnly($principal);
        $catalog = array_values(array_filter($this->catalog->apps(), fn (array $app) => $admin || ! $app['adminOnly']));
        $installed = [];
        foreach ($this->apps->apps($user)->apps as $app) {
            $installed[$app->slug] = ($installed[$app->slug] ?? 0) + 1;
        }

        return view('docker.store', [
            'user' => $user,
            'catalog' => $catalog,
            'props' => [...$this->common($user, $principal, $policy, 'store'), 'catalog' => $catalog, 'installed' => $installed],
        ]);
    }

    public function detail(string $user, string $slug, Principal $principal, DockerPolicy $policy): View
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $entry = $this->catalog->find($slug);
        abort_if($entry === null || ($entry['adminOnly'] && ! $policy->installAdminOnly($principal)), 404);
        $app = $this->catalog->app($slug);
        $apps = $this->apps->apps($user);
        $names = array_map(fn ($installed) => $installed->name, $apps->apps);

        return view('docker.detail', [
            'user' => $user,
            'app' => $app,
            'props' => [
                ...$this->common($user, $principal, $policy, 'detail'),
                'catalogApp' => $app,
                'domains' => $policy->manageApps($principal, $user) ? $this->apps->freeDomains($user, $apps) : [],
                'names' => $names,
                'hasRoom' => $apps->hasRoom(),
                'limit' => $apps->limit,
            ],
        ]);
    }

    public function show(string $user, string $name, Principal $principal, DockerPolicy $policy): View
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $app = $this->apps->show($user, $name)->toArray();

        return view('docker.apps.show', [
            'user' => $user,
            'app' => $app,
            'props' => [
                ...$this->common($user, $principal, $policy, 'app'),
                'app' => $app,
                'task' => $this->apps->task($user, $name)->toArray(),
                'domains' => $policy->manageApps($principal, $user) ? $this->apps->freeDomains($user, $this->apps->apps($user)) : [],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function common(string $user, Principal $principal, DockerPolicy $policy, string $view): array
    {
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }

        return [
            'view' => $view,
            'user' => $user,
            'base' => preg_replace('#/apps$#', '/api', route('docker.apps.index', ['user' => $user])),
            'pages' => [
                'list' => route('docker.apps.index', ['user' => $user]),
                'store' => route('docker.store', ['user' => $user]),
                'detail' => route('docker.store.show', ['user' => $user, 'slug' => '__SLUG__']),
                'show' => route('docker.apps.show', ['user' => $user, 'name' => '__NAME__']),
                'engine' => $policy->manageEngine($principal) ? route('server.docker') : null,
                'domains' => route('web.domains.create'),
                'ports' => $this->ports->view($principal, $user) ? route('ports.index') : null,
                // File manager (E3): the folder of an app
                'files' => $this->config->is('FILE_MANAGER', 'true') && $this->files->view($principal, $user) ? route('files.show', ['user' => $user]) : null,
            ],
            'abilities' => $policy->appAbilities($principal, $user),
            'readOnly' => $principal->isReadOnly(),
            'strings' => $strings,
        ];
    }
}
