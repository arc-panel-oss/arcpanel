<?php

namespace App\Http\Controllers\Docker;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\DockerPolicy;
use App\Services\Docker\DockerEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Server → Docker (E4 spec §7): the state of the engine, the last report of
 * v-check-sys-docker and the "Install Docker engine" task. Administrators
 * only.
 */
final class DockerEngineController extends Controller
{
    /** Strings of the island on this page */
    public const STRINGS = [
        'Apps', 'Compose', 'Containers', 'Data root', 'Disk free', 'Docker engine', 'Done', 'Failed', 'Findings',
        'Hide the output', 'Images', 'Install Docker engine', 'Installing the Docker engine…', 'Last check', 'MB',
        'Not installed', 'Repaired', 'Running', 'Show the output', 'Something went wrong', 'Stopped', 'The Docker engine is installed.',
        'The engine runs every Docker app of the server. Users never get access to it: every app runs as its user, without privileges.',
        'Type', 'User', 'Users', 'Version', 'App', 'Detail',
        'Checked every 5 minutes', 'Docker is not installed', 'Install the engine to run Docker apps from the catalog. Every app runs as its user, without privileges.',
        'Last installation', 'No problem found', 'Not checked yet', 'Not repaired', 'Open the Services page', 'Read-only: changes are disabled.', 'Refresh',
        'The engine and every Docker app passed the last check.', 'The first check of the engine and its apps runs within 5 minutes.','The engine is stopped: no Docker app runs until the docker service starts again.',
        'GB', 'Usage', 'Used by Docker',
    ];

    public function __construct(
        private readonly DockerEngineService $engine,
    ) {}

    public function page(Principal $principal, DockerPolicy $policy): View
    {
        abort_unless($policy->manageEngine($principal), 403);
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }
        $status = $this->engine->status();

        return view('docker.engine', [
            'status' => $status,
            'props' => [
                'view' => 'engine',
                'base' => route('server.docker').'/api',
                'appsUrl' => route('docker-apps'),
                'servicesUrl' => route('server.services'),
                'status' => $status,
                'task' => $this->engine->task()->toArray(),
                'readOnly' => $principal->isReadOnly(),
                'strings' => $strings,
            ],
        ]);
    }

    public function status(Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageEngine($principal), 403);

        return response()->json(['status' => $this->engine->status()]);
    }

    public function install(Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageEngine($principal), 403);
        $id = $this->engine->startInstall();

        return response()->json(['id' => $id, 'task' => $this->engine->task()->toArray()], 202);
    }

    public function task(Request $request, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageEngine($principal), 403);
        $offset = (int) ($request->validate(['offset' => ['nullable', 'integer', 'min:0']])['offset'] ?? 0);

        return response()->json(['task' => $this->engine->task($offset)->toArray()]);
    }
}
