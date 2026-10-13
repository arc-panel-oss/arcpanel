<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Api\SystemTaskStarter;
use App\Arc\Secret;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ReplacePhpIniRequest;
use App\Http\Requests\Api\V2\SetPhpExtensionRequest;
use App\Http\Requests\Api\V2\UpdatePhpIniValuesRequest;
use App\Http\Resources\V2\PhpExtensionResource;
use App\Http\Resources\V2\PhpIniBackupResource;
use App\Http\Resources\V2\PhpIniResource;
use App\Http\Resources\V2\PhpVersionResource;
use App\Policies\ServerPolicy;
use App\Services\Php\PhpManager;
use App\Services\Server\SystemTaskService;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The PHP Manager for administrators (E41 spec 2026-10-12-001 §9): PHP
 * versions of the catalog, their extensions and the php.ini of PHP-FPM and
 * of the command line. Installs and removals run as the system task of the
 * server (`202` with the task `sys:ID`); toggles and php.ini changes run in
 * the request. A php.ini is validated with the version's PHP before it is
 * saved; `?dry_run=1` validates only and answers the findings.
 *
 * @tags Server
 */
final class PhpController extends Controller
{
    public function __construct(
        private readonly PhpManager $php,
        private readonly Paginates $pages,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
    ) {}

    /**
     * List the PHP versions
     *
     * Every version of the panel's catalog and every installed one, oldest
     * first, with its support status, end of life, whether it may be
     * installed and the web domains that use it.
     *
     * @operationId listPhpVersions
     */
    public function index(Request $request, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewPhp($principal), 403);
        $rows = array_values(array_map(PhpVersionResource::fromManager(...), $this->php->versions()));
        $page = $this->pages->slice($request, $rows);

        return PhpVersionResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Get a PHP version
     *
     * @operationId getPhpVersion
     */
    public function show(string $php, Principal $principal, ServerPolicy $policy): PhpVersionResource
    {
        abort_unless($policy->viewPhp($principal), 403);

        return new PhpVersionResource(PhpVersionResource::fromManager($this->version($php)));
    }

    /**
     * Install a PHP version
     *
     * Installs the version from the PHP repository of the server as the
     * system task. An installed version answers `409 E_EXISTS`, one at its
     * end of life `422`, one the repository does not have `404`; another
     * running system task answers `409 E_INUSE` with `existing`.
     *
     * @operationId installPhpVersion
     *
     * @status 202
     */
    public function install(string $php, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->version($php);

        return $starter->start(SystemTaskService::step(sprintf(__('Install PHP %s'), $php), 'php', [$php, '-']));
    }

    /**
     * Remove a PHP version
     *
     * Removes an installed version with its extensions, as the system task.
     * The default version and a version that web domains use answer `403`.
     *
     * @operationId removePhpVersion
     *
     * @status 202
     */
    public function remove(string $php, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->version($php);

        return $starter->start(SystemTaskService::step(sprintf(__('Remove PHP %s'), $php), 'php', ['-', $php]));
    }

    /**
     * List the extensions of a PHP version
     *
     * The extension catalog entries that apply to the version with their
     * state for PHP-FPM and the command line, then the modules enabled on
     * the server that the catalog does not list (`catalog` false).
     *
     * @operationId listPhpExtensions
     */
    public function extensions(Request $request, string $php, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewPhp($principal), 403);
        $this->installed($php);
        $page = $this->pages->slice($request, array_values($this->php->extensions($php)));

        return PhpExtensionResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Install or turn on and off a PHP extension
     *
     * `installed: true` installs a catalog extension as the system task
     * (`202`). `fpm` and `cli` turn the module of an installed extension on
     * or off for that SAPI; the answer is the extension. Required modules
     * cannot be turned off (`403`), conflicting ones not on together
     * (`409 E_INUSE`). A change that stops PHP-FPM is undone.
     *
     * @operationId setPhpExtension
     */
    public function setExtension(SetPhpExtensionRequest $request, string $php, string $name, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): PhpExtensionResource|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installed($php);
        $extension = $this->catalogExtension($php, $name);
        $data = $request->validated();

        if (($data['installed'] ?? false) && ! $extension['installed']) {
            return $starter->start(SystemTaskService::step(sprintf(__('Install %s'), $name), 'php-ext', [$php, $name, '-']));
        }
        if (! $extension['installed']) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('The extension %s is not installed for PHP %s.'), $name, $php), 404);
        }
        $steps = [];
        foreach (['fpm', 'cli'] as $sapi) {
            if (array_key_exists($sapi, $data) && (bool) $data[$sapi] !== $extension[$sapi]) {
                $state = $data[$sapi] ? 'on' : 'off';
                $steps[] = new Step(sprintf(__('Turn %1$s %2$s for %3$s'), $name, $state, $sapi), 'v-change-sys-php-extension', [$php, $name, $state, $sapi]);
            }
        }
        if ($preview = $this->gate->execute($steps, $this->runner->apply(...))) {
            return $preview;
        }

        return new PhpExtensionResource($this->php->extensions($php)[$name] ?? $extension);
    }

    /**
     * Remove a PHP extension
     *
     * Purges the package of a catalog extension as the system task. Required
     * extensions, modules that ship with PHP and packages that others need
     * answer `403` or `409`.
     *
     * @operationId removePhpExtension
     *
     * @status 202
     */
    public function removeExtension(string $php, string $name, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installed($php);
        $this->catalogExtension($php, $name);

        return $starter->start(SystemTaskService::step(sprintf(__('Remove %s'), $name), 'php-ext', [$php, '-', $name]));
    }

    /**
     * Get the php.ini of a PHP version
     *
     * The directives of the php.ini form of PHP-FPM (`fpm`) or of the command
     * line (`cli`) with their value and source; `?raw=1` adds the file.
     *
     * @operationId getPhpIni
     */
    public function ini(Request $request, string $php, string $sapi, Principal $principal, ServerPolicy $policy): PhpIniResource
    {
        abort_unless($policy->viewPhp($principal), 403);
        $this->installed($php);

        return new PhpIniResource($this->iniRow($php, $sapi, $request->boolean('raw')));
    }

    /**
     * Replace the php.ini of a PHP version
     *
     * The file is validated with the version's PHP first: syntax, the values
     * of known directives, unknown directives (warnings), a start of PHP and,
     * for PHP-FPM, `php-fpm -t`. Errors answer `422 E_VALIDATION` with
     * `fields` keyed by `line:<n>`; warnings are saved and returned as
     * `findings`. The previous file is kept as a backup and comes back when
     * PHP-FPM does not run afterwards (`500 E_RESTART`).
     *
     * @operationId replacePhpIni
     */
    public function replaceIni(ReplacePhpIniRequest $request, string $php, string $sapi, Principal $principal, ServerPolicy $policy): PhpIniResource|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installed($php);
        // The content as sent: TrimStrings would drop the final line break
        $content = json_decode($request->getContent(), true)['content'] ?? '';
        $content = str_replace("\r\n", "\n", is_string($content) ? $content : '');
        $step = new Step(sprintf(__('Replace the php.ini of PHP %s'), $php), 'v-change-sys-php-ini', [$php, $sapi, Secret::file($content)]);

        return $this->saveIni($php, $sapi, $step, fn () => $this->php->checkIni($php, $sapi, $content));
    }

    /**
     * Change directives of the php.ini of a PHP version
     *
     * Directives of the php.ini form are changed in place (or added to the
     * `[PHP]` section) and the result is validated and saved as for
     * `replacePhpIni`.
     *
     * @operationId updatePhpIniValues
     */
    public function updateIni(UpdatePhpIniValuesRequest $request, string $php, string $sapi, Principal $principal, ServerPolicy $policy): PhpIniResource|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installed($php);
        $values = $request->directives();
        $step = new Step(sprintf(__('Change the php.ini of PHP %s'), $php), 'v-change-sys-php-ini-values', [$php, $sapi, Secret::file(PhpManager::valuesFile($values))]);

        return $this->saveIni($php, $sapi, $step, fn () => $this->php->checkIniValues($php, $sapi, $values));
    }

    /**
     * List the php.ini backups
     *
     * The ten newest earlier versions of the file, newest first.
     *
     * @operationId listPhpIniBackups
     */
    public function backups(Request $request, string $php, string $sapi, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewPhp($principal), 403);
        $this->installed($php);
        $page = $this->pages->slice($request, $this->php->iniBackups($php, $sapi));

        return PhpIniBackupResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Restore a php.ini backup
     *
     * The backup is validated and saved as for `replacePhpIni`; the current
     * file becomes a backup itself.
     *
     * @operationId restorePhpIniBackup
     */
    public function restore(string $php, string $sapi, string $backup, Principal $principal, ServerPolicy $policy): PhpIniResource|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->installed($php);
        abort_unless(in_array($backup, array_column($this->php->iniBackups($php, $sapi), 'id'), true), 404);
        $step = new Step(sprintf(__('Restore backup %s'), $backup), 'v-rollback-sys-php-ini', [$php, $sapi, $backup]);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? new PhpIniResource($this->iniRow($php, $sapi, false));
    }

    /**
     * Validate, then save: errors answer 422 with the findings, a dry run the
     * plan and the findings.
     *
     * @param  \Closure(): (list<array{line: int, level: string, directive: string, message: string}>|null)  $check
     */
    private function saveIni(string $php, string $sapi, Step $step, \Closure $check): PhpIniResource|JsonResponse
    {
        $this->gate->authorize([$step], ['v-check-sys-php-ini']);
        $findings = $check();
        if ($findings === null) {
            throw new ApiException(ErrorCode::Invalid, __('The php.ini could not be checked.'), 422);
        }
        $errors = array_values(array_filter($findings, fn (array $f) => $f['level'] === 'error'));
        if ($this->gate->dryRun()) {
            return response()->json(['data' => [
                'dry_run' => true,
                'valid' => $errors === [],
                'steps' => [PlanGate::describe($step)],
                'findings' => $findings,
            ]]);
        }
        if ($errors !== []) {
            $fields = [];
            foreach ($errors as $error) {
                $fields['line:'.$error['line']][] = $error['message'];
            }
            throw new ApiException(ErrorCode::Validation, __('The php.ini has errors and was not saved.'), 422, ['fields' => $fields, 'findings' => $findings]);
        }
        $this->gate->assertApplied($this->runner->apply([$step]));

        return new PhpIniResource($this->iniRow($php, $sapi, false) + ['findings' => $findings]);
    }

    /** @return array<string, mixed> */
    private function iniRow(string $php, string $sapi, bool $raw): array
    {
        $ini = $this->php->ini($php, $sapi);
        abort_if($ini === null, 404);
        $row = ['version' => $php, 'sapi' => $sapi] + $ini;
        if ($raw) {
            $row['content'] = $this->php->iniContent($php, $sapi);
        }

        return $row;
    }

    /** @return array<string, mixed> a version of the catalog or an installed one, else 404 */
    private function version(string $php): array
    {
        return $this->php->version($php) ?? throw new ApiException(ErrorCode::NotExist, sprintf(__('PHP %s is not in the version catalog.'), $php), 404);
    }

    private function installed(string $php): void
    {
        if (! ($this->php->version($php)['installed'] ?? false)) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('PHP %s is not installed.'), $php), 404);
        }
    }

    /** @return array<string, mixed> */
    private function catalogExtension(string $php, string $name): array
    {
        $extension = $this->php->extensions($php)[$name] ?? null;
        if ($extension === null || ! $extension['catalog']) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('%s is not an extension of the catalog.'), $name), 404);
        }

        return $extension;
    }
}
