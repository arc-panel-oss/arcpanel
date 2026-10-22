<?php

namespace App\Http\Controllers\Backups;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backups\ExclusionsRequest;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupExclusions;
use App\Services\Backups\BackupService;
use App\Services\Docker\DockerAppService;
use App\Services\Node\NodeAccess;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * Backup exclusions (web/edit/backup/exclusions; E7 batch 1 spec §10.2 and
 * D5): since E48 (spec §8.1) web domains, mail domains and databases are
 * lists of the account's records, cron jobs a switch and user folders free
 * entries; DNS is kept as it is; toggles for the Node.js runtimes (E13) and
 * each Docker app (E4).
 */
final class ExclusionController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function edit(string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners, NodeAccess $node, DockerAppService $docker): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $exclusions = $this->backups->exclusions($user);

        return view('backups.exclusions', [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user),
            'incremental' => $this->backups->incremental($user),
            'exclusions' => $exclusions,
            'lists' => self::lists($exclusions, $this->backups->objectNames($user)),
            'userLines' => implode("\n", array_diff($exclusions->get('USER'), array_keys($this->toggles($user, $exclusions, $node, $docker)))),
            'toggles' => $this->toggles($user, $exclusions, $node, $docker),
        ]);
    }

    public function update(ExclusionsRequest $request, string $user, Principal $principal, BackupPolicy $policy, NodeAccess $node, DockerAppService $docker): RedirectResponse
    {
        abort_unless($policy->editExclusions($principal, $user), 403);
        $current = $this->backups->exclusions($user);

        $toggles = [];
        foreach ($this->toggles($user, $current, $node, $docker) as $path => $toggle) {
            $toggles[$path] = in_array($path, (array) $request->input('toggles', []), true);
        }

        $updated = $current->with([
            'WEB' => $request->entries('WEB'),
            'MAIL' => $request->entries('MAIL'),
            'DB' => $request->entries('DB'),
            // The cron jobs switch (E48 spec §8.1): all of them or none
            'CRON' => $request->boolean('cron') ? ['*'] : [],
            'USER' => BackupExclusions::mergeToggles(BackupExclusions::parseLines($request->input('userdir')), $toggles),
        ]);

        $result = $this->backups->saveExclusions($user, $updated);
        $back = redirect()->route('backups.exclusions.edit', ['user' => $user]);
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->message());
        }

        return $back->with('ok', __('Changes have been saved.'));
    }

    /**
     * The rows of each structured list (E48 spec §8.1): the account's own
     * records plus any name the exclusions already hold, each with whether
     * it is left out and its folders or accounts; "all" when the list is "*".
     *
     * @param  array{WEB: list<string>, MAIL: list<string>, DB: list<string>}  $objects
     * @return array<string, array{all: bool, rows: array<string, array{excluded: bool, parts: string}>}>
     */
    public static function lists(BackupExclusions $exclusions, array $objects): array
    {
        $lists = [];
        foreach (['WEB', 'MAIL', 'DB'] as $key) {
            $current = [];
            foreach ($exclusions->get($key) as $entry) {
                $parts = explode(':', $entry);
                $current[array_shift($parts)] = implode(' ', $parts);
            }
            $all = array_key_exists('*', $current);
            unset($current['*']);
            $names = array_unique([...$objects[$key], ...array_keys($current)]);
            sort($names);
            $rows = [];
            foreach ($names as $name) {
                $rows[$name] = ['excluded' => array_key_exists($name, $current), 'parts' => $current[$name] ?? ''];
            }
            $lists[$key] = ['all' => $all, 'rows' => $rows];
        }

        return $lists;
    }

    /**
     * Home directory paths with a switch of their own: "Node.js runtimes"
     * when the package includes Node.js or ~/.nvm is already excluded, and
     * one per Docker app.
     *
     * @return array<string, array{label: string, hint: string, on: bool}>
     */
    private function toggles(string $user, BackupExclusions $exclusions, NodeAccess $node, DockerAppService $docker): array
    {
        $toggles = [];
        if ($node->allowed($user) || $exclusions->excludesPath(BackupExclusions::NODE_ENTRY)) {
            $toggles[BackupExclusions::NODE_ENTRY] = [
                'label' => __('Node.js runtimes (~/.nvm)'),
                'hint' => __('Excluded runtimes are installed again from the shared cache after a restore.'),
                'on' => $exclusions->excludesPath(BackupExclusions::NODE_ENTRY),
            ];
        }
        try {
            $apps = $docker->apps($user)->apps;
        } catch (Throwable) {
            $apps = [];
        }
        foreach ($apps as $app) {
            $path = 'apps/'.$app->name;
            $toggles[$path] = [
                'label' => sprintf(__('Docker app %s (~/apps/%s)'), $app->appName !== '' ? $app->appName : $app->name, $app->name),
                'hint' => __('Its data and database dumps are left out of the backup.'),
                'on' => $exclusions->excludesPath($path),
            ];
        }

        return $toggles;
    }
}
