<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Php\PhpManager;
use App\Services\Server\ServiceConfigs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Config editors of the services (web/edit/server/<service>), E7 batch 3
 * spec §9: the service-editor island loads the files and saves them one by
 * one.
 */
final class ServiceConfigController extends Controller
{
    /** Legacy editors that are pages of their own. */
    private const REDIRECTS = ['nftables' => 'server.firewall', 'iptables' => 'server.firewall', 'web-terminal' => 'server.services'];

    /** Editors of php.ini, which the PHP page replaces (E41 §6.5). */
    private const PHP_EDITORS = ['php', 'php-fpm', 'php5-fpm'];

    public function __construct(private readonly ServiceConfigs $configs) {}

    public function show(string $service, Principal $principal, ServerPolicy $policy, PhpManager $php): View|RedirectResponse
    {
        abort_unless($policy->viewConfig($principal), 403);
        if (isset(self::REDIRECTS[$service])) {
            return redirect()->route(self::REDIRECTS[$service]);
        }
        // Each version has its own php.ini, edited on the PHP page (E41 §6.5)
        if (in_array($service, self::PHP_EDITORS, true)) {
            $default = $php->defaultVersion();

            return $default !== ''
                ? redirect()->route('server.php.show', ['version' => $default, 'tab' => 'ini-fpm'])
                : redirect()->route('server.php.index');
        }
        abort_unless(ServiceConfigs::exists($service), 404);
        $editor = $this->configs->load($service);
        abort_if($editor === null, 404);

        $canEdit = $policy->editConfig($principal);
        $files = array_map(fn (array $file) => $file + ['writable' => $canEdit && ServiceConfigs::writable($service, $file['index'])], $editor['files']);

        return view('server.services.config', [
            'service' => $service,
            'props' => [
                'service' => $service,
                'files' => $files,
                'options' => $editor['options'],
                'language' => $editor['language'],
                'alwaysRestart' => $editor['alwaysRestart'],
                'saveUrl' => route('server.services.config.save', ['service' => $service]),
                'strings' => self::strings(),
            ],
        ]);
    }

    /** POST (JSON) one file: {index, content, restart}. */
    public function save(Request $request, string $service, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->editConfig($principal), 403);
        abort_unless(ServiceConfigs::exists($service), 404);
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:20'],
            'content' => ['present', 'nullable', 'string', 'max:1048576'],
            'restart' => ['required', 'boolean'],
        ]);
        abort_unless(ServiceConfigs::writable($service, (int) $data['index']), 403);
        // The content as sent: TrimStrings would drop the final line break
        $content = json_decode($request->getContent(), true)['content'] ?? '';

        $result = $this->configs->save($service, (int) $data['index'], is_string($content) ? $content : '', (bool) $data['restart']);
        if (! $result->ok()) {
            $message = $result->message() !== '' ? $result->message() : sprintf(__('Error code: %s'), $result->exitCode);

            return response()->json(['error' => ['code' => 'E_COMMAND', 'message' => $message]], 422);
        }

        return response()->json(['message' => __('Changes have been saved.')]);
    }

    /** @return array<string, string> strings of the island */
    private static function strings(): array
    {
        $keys = [
            'Basic options', 'Restart the service after saving', 'Save', 'Saving…', 'Changes have been saved.',
            'Unsaved changes', 'Read-only', 'Content of %s', 'The setting is not in the file.', 'This file cannot be changed here.',
            'Leave this page? Your changes are not saved.',
        ];

        return array_combine($keys, array_map(fn (string $key) => __($key), $keys));
    }
}
