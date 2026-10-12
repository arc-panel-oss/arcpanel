<?php

namespace App\Http\Controllers\Server;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Php\PhpManager;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Server → PHP (E41 spec 2026-10-12-001 §8): the versions of the catalog,
 * and per installed version its overview, extensions and the php.ini of
 * PHP-FPM and of the command line. Installs and removals run as the system
 * task; toggles and php.ini changes run in the request.
 */
final class PhpController extends Controller
{
    public const TABS = ['overview', 'extensions', 'ini-fpm', 'ini-cli'];

    public function __construct(
        private readonly PhpManager $php,
        private readonly SystemTaskService $tasks,
    ) {}

    public function index(Principal $principal, ServerPolicy $policy, SysConfig $config): View
    {
        abort_unless($policy->viewPhp($principal), 403);

        return view('server.php.index', [
            'versions' => $this->php->versions(),
            'default' => $this->php->defaultVersion(),
            'task' => $this->tasks->task(),
            'fpm' => $config->get('WEB_BACKEND') !== '',
            'canManage' => $policy->managePhp($principal),
        ]);
    }

    public function show(string $version, Principal $principal, ServerPolicy $policy, SysConfig $config, ?string $tab = null): View
    {
        abort_unless($policy->viewPhp($principal), 403);
        $state = $this->php->version($version);
        abort_if($state === null || ! $state['installed'], 404);
        $tab ??= 'overview';
        $canManage = $policy->managePhp($principal) && $config->get('WEB_BACKEND') !== '';

        $data = match ($tab) {
            'overview' => ['service' => $this->php->serviceState($version)],
            'extensions' => ['groups' => $this->php->groupedExtensions($version), 'task' => $this->tasks->task()],
            default => $this->iniData($version, $tab === 'ini-cli' ? 'cli' : 'fpm', $canManage),
        };

        return view('server.php.show', $data + [
            'version' => $version,
            'state' => $state,
            'tab' => $tab,
            'canManage' => $canManage,
        ]);
    }

    /** POST versions: install or remove one version as the system task. */
    public function versions(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['install', 'remove'])],
            'version' => ['required', 'string', 'regex:/^\d{1,2}\.\d{1,2}$/'],
        ]);
        $state = $this->php->version($data['version']);
        abort_if($state === null, 404);

        $install = $data['action'] === 'install';
        $result = $install ? $this->php->startVersions([$data['version']], []) : $this->php->startVersions([], [$data['version']]);
        $back = redirect()->route('server.php.index');
        if (! $result->ok()) {
            return $back->with('error', SystemTaskService::refusal($result));
        }

        return $back->with('ok', sprintf($install ? __('PHP %s is being installed.') : __('PHP %s is being removed.'), $data['version']));
    }

    /** POST default: the version of the php command and of the default backend template. */
    public function default(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $data = $request->validate(['version' => ['required', 'string', Rule::in($this->php->installed())]]);

        $result = $this->php->setDefault($data['version']);
        $back = redirect()->route('server.php.index');

        return $result->ok()
            ? $back->with('ok', sprintf(__('PHP %s is now the default version.'), $data['version']))
            : $back->with('error', $result->errorMessage());
    }

    public function reload(string $version, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);

        $result = $this->php->reload($version);
        $back = redirect()->route('server.php.show', ['version' => $version]);

        return $result->ok()
            ? $back->with('ok', sprintf(__('PHP-FPM %s has been reloaded.'), $version))
            : $back->with('error', $result->errorMessage());
    }

    /** POST extensions: install or remove one extension as the system task. */
    public function extensions(Request $request, string $version, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);
        $data = $request->validate([
            'action' => ['required', Rule::in(['install', 'remove'])],
            'name' => ['required', 'string', 'regex:/^[a-z0-9_]{1,32}$/'],
        ]);
        $extension = $this->php->extensions($version)[$data['name']] ?? null;
        abort_if($extension === null || ! $extension['catalog'], 404);

        $install = $data['action'] === 'install';
        $result = $this->php->startExtensions($version, $install ? [$data['name']] : [], $install ? [] : [$data['name']]);
        $back = redirect()->route('server.php.show', ['version' => $version, 'tab' => 'extensions']);
        if (! $result->ok()) {
            return $back->with('error', SystemTaskService::refusal($result));
        }

        return $back->with('ok', sprintf($install ? __('%s is being installed.') : __('%s is being removed.'), $data['name']));
    }

    /** POST (JSON) extensions/{name}: {state: on|off, sapi: fpm|cli|""} */
    public function toggle(Request $request, string $version, string $name, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);
        $data = $request->validate([
            'state' => ['required', Rule::in(['on', 'off'])],
            'sapi' => ['nullable', Rule::in(['fpm', 'cli', ''])],
        ]);
        $extensions = $this->php->extensions($version);
        abort_if(! isset($extensions[$name]) || ! $extensions[$name]['catalog'], 404);

        $result = $this->php->toggleExtension($version, $name, $data['state'] === 'on', (string) ($data['sapi'] ?? ''));
        if (! $result->ok()) {
            return response()->json(['error' => ['code' => 'E_COMMAND', 'message' => $result->errorMessage()]], 422);
        }
        $after = $this->php->extensions($version)[$name] ?? $extensions[$name];

        return response()->json(['fpm' => $after['fpm'], 'cli' => $after['cli'], 'message' => __('Changes have been saved.')]);
    }

    /** POST ini/{sapi}: the directives of the form. */
    public function iniValues(Request $request, string $version, string $sapi, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);
        $current = $this->php->ini($version, $sapi);
        abort_if($current === null, 404);

        $rules = [];
        foreach (PhpManager::FORM_DIRECTIVES as $directive) {
            $rules[self::field($directive)] = in_array($directive, PhpManager::BOOLEAN_DIRECTIVES, true)
                ? ['nullable', 'boolean']
                : ['nullable', 'string', 'max:4096', 'not_regex:/["$\\\\\r\n]/'];
        }
        $request->validate($rules + ['disable_functions_list' => ['nullable', 'array'], 'disable_functions_list.*' => ['string', 'regex:/^[A-Za-z_][A-Za-z0-9_\\\\]*$/']]);

        // Only what changed goes to the command
        $changes = [];
        foreach (PhpManager::FORM_DIRECTIVES as $directive) {
            $field = self::field($directive);
            if (in_array($directive, PhpManager::BOOLEAN_DIRECTIVES, true)) {
                $value = $request->boolean($field) ? 'On' : 'Off';
                $before = self::truthy($current['values'][$directive]['value']) ? 'On' : 'Off';
            } elseif ($directive === 'disable_functions' && $request->has('disable_functions_editor')) {
                // The list editor: an empty list sends only its marker field
                $value = implode(',', array_values(array_unique(array_filter(array_map('trim', (array) $request->input('disable_functions_list', []))))));
                $before = $current['values'][$directive]['value'];
            } elseif ($request->has($field)) {
                $value = trim((string) $request->input($field, ''));
                $before = $current['values'][$directive]['value'];
            } else {
                continue;
            }
            if ($value !== $before) {
                $changes[$directive] = $value;
            }
        }

        $back = redirect()->route('server.php.show', ['version' => $version, 'tab' => 'ini-'.$sapi]);
        if ($changes === []) {
            return $back->with('ok', __('Nothing has changed.'));
        }
        $result = $this->php->saveIniValues($version, $sapi, $changes);
        if ($result->ok()) {
            return $back->with('ok', __('Changes have been saved.'))->with('ok_detail', trim($result->stdout));
        }

        // The errors of the form's directives go to their fields; the rest stays in the notice
        $fieldErrors = [];
        foreach ($this->php->checkIniValues($version, $sapi, $changes) ?? [] as $finding) {
            if ($finding['level'] === 'error' && in_array($finding['directive'], PhpManager::FORM_DIRECTIVES, true)) {
                $fieldErrors[self::field($finding['directive'])] ??= $finding['message'];
            }
        }
        if ($fieldErrors !== []) {
            return $back->withInput()->withErrors($fieldErrors)
                ->with('error', trans_choice('The php.ini was not saved: :count setting needs a change.|The php.ini was not saved: :count settings need a change.', count($fieldErrors), ['count' => count($fieldErrors)]));
        }

        return $back->withInput()->with('error', __('The php.ini was not saved.'))->with('error_detail', trim($result->stdout."\n".$result->stderr));
    }

    /** POST (JSON) ini/{sapi}/check: {content} → {findings} */
    public function iniCheck(Request $request, string $version, string $sapi, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);
        $content = $this->content($request);

        $findings = $this->php->checkIni($version, $sapi, $content);
        if ($findings === null) {
            return response()->json(['error' => ['code' => 'E_COMMAND', 'message' => __('The file could not be checked.')]], 422);
        }

        return response()->json(['findings' => $findings]);
    }

    /** POST (JSON) ini/{sapi}/file: {content} → saved, or {findings} with 422 */
    public function iniFile(Request $request, string $version, string $sapi, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);
        $content = $this->content($request);

        $findings = $this->php->checkIni($version, $sapi, $content);
        if ($findings !== null && array_filter($findings, fn (array $f) => $f['level'] === 'error') !== []) {
            return response()->json(['error' => ['code' => 'E_INVALID', 'message' => __('The php.ini has errors and was not saved.')], 'findings' => $findings], 422);
        }
        $result = $this->php->saveIni($version, $sapi, $content);
        if (! $result->ok()) {
            return response()->json(['error' => ['code' => 'E_COMMAND', 'message' => $result->errorMessage()], 'findings' => $findings ?? []], 422);
        }

        return response()->json(['message' => __('Changes have been saved.'), 'findings' => $findings ?? []]);
    }

    public function iniRestore(string $version, string $sapi, string $backup, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installedOr404($version);

        $result = $this->php->restoreIni($version, $sapi, $backup);
        $back = redirect()->route('server.php.show', ['version' => $version, 'tab' => 'ini-'.$sapi]);

        return $result->ok()
            ? $back->with('ok', sprintf(__('Backup %s has been restored.'), $backup))
            : $back->with('error', $result->errorMessage());
    }

    /** @return array<string, mixed> data of a php.ini tab */
    private function iniData(string $version, string $sapi, bool $canManage): array
    {
        $ini = $this->php->ini($version, $sapi);
        abort_if($ini === null, 404);

        return [
            'sapi' => $sapi,
            'ini' => $ini,
            'backups' => $this->php->iniBackups($version, $sapi),
            'editor' => [
                'version' => $version,
                'sapi' => $sapi,
                'file' => $ini['file'],
                'content' => $this->php->iniContent($version, $sapi) ?? '',
                'writable' => $canManage,
                'checkUrl' => route('server.php.ini.check', ['version' => $version, 'sapi' => $sapi]),
                'saveUrl' => route('server.php.ini.file', ['version' => $version, 'sapi' => $sapi]),
                'strings' => self::editorStrings(),
            ],
        ];
    }

    /** The form field of a directive: dots are not allowed in field names. */
    public static function field(string $directive): string
    {
        return str_replace('.', '__', $directive);
    }

    public static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true);
    }

    private function installedOr404(string $version): void
    {
        $state = $this->php->version($version);
        abort_if($state === null || ! $state['installed'], 404);
    }

    /** The content as sent: TrimStrings would drop the final line break. */
    private function content(Request $request): string
    {
        $request->validate(['content' => ['present', 'nullable', 'string', 'max:1048576']]);
        $content = json_decode($request->getContent(), true)['content'] ?? '';

        return is_string($content) ? $content : '';
    }

    /** @return array<string, string> */
    private static function editorStrings(): array
    {
        $keys = [
            'Validate', 'Validating…', 'Save', 'Saving…', 'Changes have been saved.', 'No problems found.', 'Unsaved changes',
            'Read-only', 'Content of %s', 'Line %s', 'Error', 'Warning', 'Save with %s warnings?',
            'Leave this page? Your changes are not saved.', 'The php.ini has errors and was not saved.',
        ];

        return array_combine($keys, array_map(fn (string $key) => __($key), $keys));
    }
}
