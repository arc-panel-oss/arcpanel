<?php

namespace App\Services\Node;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\TemplateCatalog;
use App\Services\WebDomains\WebDomainService;

/**
 * Node.js apps of a user (E14 spec §6.1): one method per command through
 * ArcCommand. Installing dependencies and building start a task of the
 * E13 runner; NodePolicy decides who may do what and the commands check the
 * package keys again on the server.
 */
final class NodeAppService
{
    /** pm2 process names (func/nodeapp.sh nodeapp_name_valid) */
    public const NAME = '/^[a-z0-9][a-z0-9._-]{0,39}$/';

    /**
     * A relative path without "..", or "." (nodeapp_relpath_valid); "/"
     * delimiters: the API document keeps any other one in its pattern.
     */
    public const PATH = '/^(\.|[A-Za-z0-9_][A-Za-z0-9._\/@+-]{0,199})$/';

    /** A package.json script */
    public const SCRIPT = '/^[A-Za-z0-9][A-Za-z0-9:._-]{0,63}$/';

    /** Environment keys; PORT, HOST, NODE_ENV and PATH are set by the panel */
    public const ENV_KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public const RESERVED_ENV = ['PORT', 'HOST', 'NODE_ENV', 'PATH'];

    public const ACTIONS = ['start', 'stop', 'restart', 'reload'];

    public const STREAMS = ['out', 'err', 'install', 'build'];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly NodeService $node,
        private readonly WebDomainService $domains,
        private readonly TemplateCatalog $catalog,
        private readonly PlanRunner $plans,
    ) {}

    public function apps(string $user): NodeApps
    {
        return NodeApps::from($this->arc->json('v-list-node-apps', [$user]));
    }

    public function show(string $user, string $domain): NodeApp
    {
        $data = $this->arc->json('v-list-node-app', [$user, $domain]);

        return NodeApp::from(is_array($data[$domain] ?? null) ? $data[$domain] : []);
    }

    /**
     * A log of the app: the last LINES lines, or what was written after
     * OFFSET.
     *
     * @return array{stream: string, size: int, offset: int, lines: list<string>}
     */
    public function logs(string $user, string $domain, string $stream, ?int $offset, int $lines = 200): array
    {
        $data = $this->arc->json('v-list-node-app-logs', [$user, $domain, $stream, $offset === null ? '' : (string) $offset, (string) $lines]);

        return [
            'stream' => (string) ($data['STREAM'] ?? $stream),
            'size' => (int) ($data['SIZE'] ?? 0),
            'offset' => (int) ($data['OFFSET'] ?? 0),
            'lines' => array_values(array_map('strval', array_filter((array) ($data['LINES'] ?? []), 'is_scalar'))),
        ];
    }

    /** @return list<array{key: string, value: string}> */
    public function env(string $user, string $domain): array
    {
        $vars = [];
        foreach ((array) ($this->arc->json('v-list-node-app-env', [$user, $domain])['VARS'] ?? []) as $row) {
            if (is_array($row) && is_string($row['KEY'] ?? null)) {
                $vars[] = ['key' => $row['KEY'], 'value' => is_scalar($row['VALUE'] ?? null) ? (string) $row['VALUE'] : ''];
            }
        }

        return $vars;
    }

    /**
     * @param  array{start: string, version: string, port: string, cwd: string, instances: int, template: string, name: string}  $app
     */
    public function create(string $user, string $domain, array $app): void
    {
        $this->arc->run('v-add-node-app', [
            $user, $domain, $app['start'], $app['version'], $app['port'], $app['cwd'],
            (string) $app['instances'], $app['template'], $app['name'],
        ]);
    }

    public function delete(string $user, string $domain, bool $keepFiles): void
    {
        $this->arc->run('v-delete-node-app', $keepFiles ? [$user, $domain, 'keep-files'] : [$user, $domain]);
    }

    public function action(string $user, string $domain, string $action): void
    {
        $this->arc->run('v-'.$action.'-node-app', [$user, $domain]);
    }

    public function changeStart(string $user, string $domain, string $start, string $cwd): void
    {
        $this->arc->run('v-change-node-app-start', [$user, $domain, $start, $cwd]);
    }

    public function changeVersion(string $user, string $domain, string $version): void
    {
        $this->arc->run('v-change-node-app-version', [$user, $domain, $version]);
    }

    public function scale(string $user, string $domain, int $instances, int $memory): void
    {
        $this->arc->run('v-change-node-app-scale', [$user, $domain, (string) $instances, (string) $memory]);
    }

    /**
     * Replace the environment; the values travel in a temp file only.
     *
     * @param  list<array{key: string, value: string}>  $vars
     */
    public function saveEnv(string $user, string $domain, array $vars): void
    {
        $content = '';
        foreach ($vars as $var) {
            $content .= $var['key'].'='.str_replace(["\r", "\n"], '', $var['value'])."\n";
        }
        $this->arc->run('v-change-node-app-env', [$user, $domain, Secret::file($content)]);
    }

    public function startInstall(string $user, string $domain): string
    {
        return $this->node->startTask($user, 'app-install', [$domain]);
    }

    public function startBuild(string $user, string $domain, string $script): string
    {
        return $this->node->startTask($user, 'app-build', [$domain, $script]);
    }

    /**
     * @param  array{start: string, version: string, port: string, cwd: string, instances: int, template: string, name: string}  $app
     */
    public function planCreate(string $user, string $domain, array $app): Step
    {
        return new Step(__('Create the Node.js app'), 'v-add-node-app', [
            $user, $domain, $app['start'], $app['version'], $app['port'], $app['cwd'],
            (string) $app['instances'], $app['template'], $app['name'],
        ]);
    }

    public function planDelete(string $user, string $domain, bool $keepFiles): Step
    {
        return new Step(__('Delete the Node.js app'), 'v-delete-node-app',
            $keepFiles ? [$user, $domain, 'keep-files'] : [$user, $domain]);
    }

    public function planAction(string $user, string $domain, string $action): Step
    {
        return new Step(__('Run :action on the Node.js app', ['action' => $action]), 'v-'.$action.'-node-app', [$user, $domain]);
    }

    public function planChangeStart(string $user, string $domain, string $start, string $cwd): Step
    {
        return new Step(__('Change the start command'), 'v-change-node-app-start', [$user, $domain, $start, $cwd]);
    }

    public function planChangeVersion(string $user, string $domain, string $version): Step
    {
        return new Step(__('Change the Node.js runtime'), 'v-change-node-app-version', [$user, $domain, $version]);
    }

    public function planScale(string $user, string $domain, int $instances, int $memory): Step
    {
        return new Step(__('Scale the Node.js app'), 'v-change-node-app-scale', [$user, $domain, (string) $instances, (string) $memory]);
    }

    /**
     * @param  list<array{key: string, value: string}>  $vars
     */
    public function planSaveEnv(string $user, string $domain, array $vars): Step
    {
        $content = '';
        foreach ($vars as $var) {
            $content .= $var['key'].'='.str_replace(["\r", "\n"], '', $var['value'])."\n";
        }

        return new Step(__('Update the environment'), 'v-change-node-app-env', [$user, $domain, Secret::file($content)]);
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        return $this->plans->apply($steps);
    }

    /**
     * Web domains of $user that can get an app (no app yet, not suspended),
     * sorted.
     *
     * @return list<string>
     */
    public function freeDomains(string $user, NodeApps $apps): array
    {
        $free = [];
        foreach ($this->domains->forUser($user) as $domain => $record) {
            if ($apps->find($domain) === null && ! $record->suspended) {
                $free[] = $domain;
            }
        }
        sort($free);

        return $free;
    }

    /**
     * Node.js templates of this server (metadata runtime "node").
     *
     * @return list<array{name: string, title: string, description: string}>
     */
    public function templates(): array
    {
        $kind = $this->catalog->activeKind();
        if ($kind === null) {
            return [];
        }
        $templates = [];
        foreach ($this->catalog->templates($kind) as $meta) {
            if ($meta->runtime === 'node' && ! $meta->internal) {
                $templates[] = ['name' => $meta->name, 'title' => __($meta->title), 'description' => __($meta->description)];
            }
        }

        return $templates;
    }

    /**
     * What package.json in CWD of the domain proposes: the start command,
     * the template and the scripts.
     *
     * @return array{packageJson: bool, start: string, template: string, scripts: list<string>}
     */
    public function detect(string $user, string $domain, string $cwd): array
    {
        $path = '/home/'.$user.'/web/'.$domain.($cwd === '.' ? '' : '/'.$cwd).'/package.json';
        $result = $this->arc->run('v-open-fs-file', [$user, $path], Options::allowFailure());
        $package = $result->ok() ? json_decode($result->stdout, true) : null;

        return self::proposal(is_array($package) ? $package : null);
    }

    /**
     * @param  array<string, mixed>|null  $package  decoded package.json
     * @return array{packageJson: bool, start: string, template: string, scripts: list<string>}
     */
    public static function proposal(?array $package): array
    {
        if ($package === null) {
            return ['packageJson' => false, 'start' => 'server.js', 'template' => 'nodejs-proxy', 'scripts' => []];
        }
        $scripts = array_values(array_filter(array_keys(is_array($package['scripts'] ?? null) ? $package['scripts'] : []),
            fn ($name) => is_string($name) && preg_match(self::SCRIPT, $name) === 1));
        $main = is_string($package['main'] ?? null) ? preg_replace('#^\./#', '', $package['main']) : '';
        $start = match (true) {
            in_array('start', $scripts, true) => 'npm:start',
            $main !== '' && preg_match(self::PATH, (string) $main) === 1 => (string) $main,
            default => 'server.js',
        };
        $deps = array_merge(
            is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
            is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [],
        );
        $template = match (true) {
            isset($deps['next']) => 'nextjs',
            isset($deps['nuxt']) => 'nuxt',
            isset($deps['@strapi/strapi']) => 'strapi',
            isset($deps['ghost']) => 'ghost',
            isset($deps['ws']) || isset($deps['socket.io']) => 'nodejs-proxy-ws',
            default => 'nodejs-proxy',
        };

        return ['packageJson' => true, 'start' => $start, 'template' => $template, 'scripts' => $scripts];
    }
}
