<?php

namespace App\Http\Controllers\ObjectStorage;

use App\Arc\ArcCommand;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ObjectStoragePolicy;
use App\Services\ObjectStorage\ObjectStorageService;
use App\Services\Server\SystemTaskService;
use App\Services\WebDomains\Step;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Server → Object Storage for administrators (E44 spec §11): install Silo
 * as the system task, publish its endpoint, see the usage of every account.
 */
final class ObjectStorageServerController extends Controller
{
    public function __construct(private readonly ObjectStorageService $storage) {}

    public function page(Principal $principal, ObjectStoragePolicy $policy, ArcCommand $arc, SystemTaskService $tasks): View
    {
        abort_unless($policy->viewServer($principal), 403);
        $silo = $this->storage->server();
        $accounts = [];
        if ($silo->installed) {
            $answer = $arc->json('v-list-sys-buckets', ['', '']);
            foreach (($answer['OBJECTS'] ?? []) as $user => $buckets) {
                if (! is_array($buckets) || $buckets === []) {
                    continue;
                }
                $row = ['user' => (string) $user, 'buckets' => 0, 'quota' => 0, 'used' => 0, 'objects' => 0];
                foreach ($buckets as $bucket) {
                    $row['buckets']++;
                    $row['quota'] += (int) ($bucket['QUOTA'] ?? 0);
                    $row['used'] += (int) ($bucket['USED'] ?? 0);
                    $row['objects'] += (int) ($bucket['OBJECTS'] ?? 0);
                }
                $accounts[] = $row;
            }
            usort($accounts, fn (array $a, array $b) => $b['used'] <=> $a['used'] ?: strcmp($a['user'], $b['user']));
        }

        return view('object-storage.server', [
            'silo' => $silo,
            'accounts' => $accounts,
            'canManage' => $policy->manageServer($principal),
            // Install and remove run as the system task: the page shows it and waits for it
            'task' => $tasks->task(),
        ]);
    }

    /** POST /server/object-storage/install {data_path}: Silo as the system task. */
    public function install(Request $request, Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServer($principal), 403);
        $data = $request->validate([
            'data_path' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/-]+$/', 'not_regex:/\.\./'],
        ], ['data_path.regex' => __('Use an absolute path of letters, digits and . _ - /.')]);
        $steps = [];
        if (($data['data_path'] ?? '') !== '') {
            $steps[] = new Step(__('Set the data path of object storage'), 'v-change-sys-config-value', ['SILO_DATA', rtrim((string) $data['data_path'], '/')]);
        }
        $steps[] = SystemTaskService::step(__('Install object storage'), 'plugin', ['silo', 'on']);
        $result = $this->storage->apply($steps);
        $back = redirect()->route('server.object-storage');
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->exitCode === ExitCode::InUse->value
                ? __('Another task is running. Wait until it has finished.') : $result->error);
        }

        return $back->with('ok', __('Object storage is being installed. This page shows it when the system task has finished.'));
    }

    /** DELETE /server/object-storage: Silo removed as the system task; refused while buckets exist. */
    public function remove(Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServer($principal), 403);
        $back = redirect()->route('server.object-storage');
        if ($this->storage->server()->buckets > 0) {
            return $back->with('error', __('Delete every bucket first: object storage is removed only when it holds no bucket.'));
        }
        $result = $this->storage->apply([SystemTaskService::step(__('Remove object storage'), 'plugin', ['silo', 'off'])]);
        if (! $result->ok()) {
            return $back->with('error', $result->exitCode === ExitCode::InUse->value
                ? __('Another task is running. Wait until it has finished.') : $result->error);
        }

        return $back->with('ok', __('Object storage is being removed. This page shows it when the system task has finished.'));
    }

    /** POST /server/object-storage/endpoint {hostname, letsencrypt} */
    public function endpoint(Request $request, Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServer($principal), 403);
        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'letsencrypt' => ['sometimes', 'boolean'],
        ]);
        $hostname = strtolower((string) ($data['hostname'] ?? ''));
        $args = $hostname !== '' ? [$hostname, $request->boolean('letsencrypt') ? 'yes' : 'no'] : ['none'];
        $result = $this->storage->apply([new Step(__('Change the object storage endpoint'), 'v-change-sys-silo-endpoint', $args)]);
        $back = redirect()->route('server.object-storage');
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->error);
        }

        return $back->with('ok', $hostname !== '' ? sprintf(__('Object storage answers on %s.'), $hostname) : __('The object storage endpoint has been removed.'));
    }
}
