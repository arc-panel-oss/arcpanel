<?php

namespace App\Services\WebDomains;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Web, proxy and backend templates with their metadata
 * (`v-list-web-templates-meta KIND json`, E6 spec §7.1), cached for five
 * minutes per kind.
 */
final class TemplateCatalog
{
    public const CACHE_TTL = 300;

    private const CACHE_KEY = 'arc.web-templates.';

    /** @var array<string, array<string, TemplateMeta>> */
    private array $loaded = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly Cache $cache,
    ) {}

    /**
     * Templates of KIND (web, proxy, backend) on this server, by name; empty
     * when the kind does not apply (no proxy or no PHP-FPM backend).
     *
     * @return array<string, TemplateMeta>
     */
    public function templates(string $kind): array
    {
        if (($kind === 'proxy' && $this->config->get('PROXY_SYSTEM') === '')
            || ($kind === 'backend' && $this->config->get('WEB_BACKEND') === '')) {
            return [];
        }

        return $this->loaded[$kind] ??= $this->load($kind);
    }

    /** @return list<string> */
    public function names(string $kind): array
    {
        return array_keys($this->templates($kind));
    }

    public function find(string $kind, string $name): ?TemplateMeta
    {
        return $this->templates($kind)[$name] ?? null;
    }

    /**
     * Kind of the template that faces visitors and may take a port: proxy on
     * NGINX in front of Apache, web on NGINX with PHP-FPM, null otherwise.
     */
    public function activeKind(): ?string
    {
        return match (true) {
            $this->config->get('WEB_SYSTEM') === 'nginx' => 'web',
            $this->config->get('PROXY_SYSTEM') === 'nginx' => 'proxy',
            default => null,
        };
    }

    /** @return list<string> names of the KIND templates that take a port */
    public function portTemplates(string $kind): array
    {
        return array_keys(array_filter($this->templates($kind), fn (TemplateMeta $meta) => $meta->portVariable() !== null));
    }

    /**
     * Options of x-ap-card-picker: internal templates are left out unless
     * they are the current one.
     *
     * @return array<string, array{title: string, description: string, category: string, badge: ?string, meta: string}>
     */
    public function pickerOptions(string $kind, ?string $current = null): array
    {
        $options = [];
        foreach ($this->templates($kind) as $name => $meta) {
            if ($meta->internal && $name !== $current) {
                continue;
            }
            $options[$name] = [
                'title' => $meta->hasMeta ? __($meta->title) : $meta->title,
                'description' => $meta->description !== '' ? __($meta->description) : '',
                'category' => $meta->category,
                'badge' => $meta->runtime !== 'none' ? $this->runtimeLabel($meta->runtime) : null,
                'meta' => $name,
            ];
        }
        uasort($options, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']));

        return $options;
    }

    /** Title of a template for lists, its name when it has no metadata. */
    public function title(string $kind, string $name): string
    {
        $meta = $this->find($kind, $name);

        return $meta !== null && $meta->hasMeta ? __($meta->title) : $name;
    }

    /** @return array<string, string> category => label, in display order */
    public function categories(): array
    {
        return [
            'general' => __('General'),
            'php-cms' => __('PHP CMS'),
            'php-framework' => __('PHP frameworks'),
            'ecommerce' => __('E-commerce'),
            'nodejs' => __('Node.js'),
            'python' => __('Python'),
            'static' => __('Static'),
            'proxy' => __('Proxy and containers'),
            'other' => __('Other'),
        ];
    }

    public function forget(): void
    {
        $this->loaded = [];
        foreach (['web', 'proxy', 'backend'] as $kind) {
            $this->cache->forget(self::CACHE_KEY.$kind);
        }
    }

    private function runtimeLabel(string $runtime): string
    {
        return match ($runtime) {
            'php' => 'PHP',
            'node' => 'Node.js',
            'python' => 'Python',
            'docker' => 'Docker',
            default => $runtime,
        };
    }

    /** @return array<string, TemplateMeta> */
    private function load(string $kind): array
    {
        $rows = $this->cache->remember(
            self::CACHE_KEY.$kind,
            self::CACHE_TTL,
            fn (): array => $this->arc->json('v-list-web-templates-meta', [$kind], Options::allowFailure()),
        );
        $templates = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $meta = TemplateMeta::from($row);
                if ($meta->name !== '') {
                    $templates[$meta->name] = $meta;
                }
            }
        }

        return $templates;
    }
}
