<?php

namespace App\Services\Packages;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Services\UserDirectory;
use App\Services\WebDomains\Step;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hosting packages and their reseller settings (E11 spec §8). The package
 * file is built as web/add/package builds it and handed to
 * v-add-user-package (or the reseller commands) through a temporary file.
 */
final class PackageService
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly UserDirectory $users,
        private readonly SysConfig $config,
    ) {}

    /** @return array<string, PackageRecord> every package but "system", by name */
    public function all(): array
    {
        $counts = [];
        foreach ($this->users->all() as $record) {
            $package = (string) ($record['PACKAGE'] ?? '');
            $counts[$package] = ($counts[$package] ?? 0) + 1;
        }

        $packages = [];
        foreach ($this->arc->json('v-list-package-tenancy', ['all']) as $name => $data) {
            if (is_string($name) && is_array($data) && $name !== 'system') {
                $packages[$name] = PackageRecord::from($name, $data, $counts[$name] ?? 0);
            }
        }
        ksort($packages);

        return $packages;
    }

    /** @return list<string> names of the packages a reseller may assign */
    public function assignableBy(string $reseller): array
    {
        return array_values(array_filter(array_keys($this->arc->json('v-list-reseller-packages', [$reseller])), 'is_string'));
    }

    public function find(string $name): PackageRecord
    {
        return $this->all()[$name] ?? throw new NotFoundHttpException;
    }

    /** @return array<string, list<string>> template lists for the form */
    public function templates(): array
    {
        $list = fn (string $command) => array_values(array_filter($this->arc->json($command, [], Options::allowFailure()), 'is_string'));

        return [
            'web' => $list('v-list-web-templates'),
            'backend' => $this->config->get('WEB_BACKEND') !== '' ? $list('v-list-web-templates-backend') : [],
            'proxy' => $this->config->get('PROXY_SYSTEM') !== '' ? $list('v-list-web-templates-proxy') : [],
            'dns' => $list('v-list-dns-templates'),
            'shells' => $list('v-list-sys-shells'),
        ];
    }

    public function resourcesLimit(): bool
    {
        return $this->config->is('RESOURCES_LIMIT');
    }

    /**
     * Content of the package file (web/add/package/index.php).
     *
     * @param  array<string, string>  $values  KEY => value
     */
    public function file(array $values): string
    {
        $keys = ['WEB_TEMPLATE', 'BACKEND_TEMPLATE', 'PROXY_TEMPLATE', 'DNS_TEMPLATE', ...PackageRecord::COUNTED, ...PackageRecord::RESOURCES,
            'RATE_LIMIT', 'NS', 'SHELL', 'BACKUPS', 'BACKUPS_INCREMENTAL'];
        $lines = [];
        foreach ($keys as $key) {
            $lines[] = $key."='".str_replace(["'", "\n", "\r"], '', $values[$key] ?? '')."'";
        }
        $lines[] = "TIME='".date('H:i:s')."'";
        $lines[] = "DATE='".date('Y-m-d')."'";

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $values
     * @param  array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string}|null  $tenancy  administrators only
     * @param  string  $ports  PORTS of the package (E12), a number or unlimited
     * @return list<Step>
     */
    public function planCreate(string $name, array $values, ?array $tenancy, string $reseller = '', string $ports = '0'): array
    {
        $file = Secret::file($this->file($values));
        if ($reseller !== '') {
            return [
                new Step(sprintf(__('Add package %s'), $name), 'v-add-reseller-package', [$reseller, $file, $name]),
                ...$this->portsStep($reseller.'_'.$name, $ports, null),
            ];
        }

        return [
            new Step(sprintf(__('Add package %s'), $name), 'v-add-user-package', [$file, $name]),
            ...$this->tenancySteps($name, $tenancy, null),
            ...$this->portsStep($name, $ports, null),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string}|null  $tenancy
     * @param  string|null  $ports  PORTS of the package (E12), null to keep it
     * @return list<Step>
     */
    public function planUpdate(PackageRecord $current, string $newName, array $values, ?array $tenancy, string $reseller = '', ?string $ports = null): array
    {
        $file = Secret::file($this->file($values));
        if ($reseller !== '') {
            return [
                new Step(__('Save the package'), 'v-change-reseller-package', [$reseller, $file, $current->name]),
                ...$this->portsStep($current->name, $ports, $current),
            ];
        }

        $steps = [
            new Step(__('Save the package'), 'v-add-user-package', [$file, $current->name, 'yes']),
            new Step(__('Apply the package to its users'), 'v-update-user-package', [$current->name]),
            ...$this->tenancySteps($current->name, $tenancy, $current),
            ...$this->portsStep($current->name, $ports, $current),
        ];
        if ($newName !== $current->name) {
            $steps[] = new Step(__('Rename the package'), 'v-rename-user-package', [$current->name, $newName]);
            $steps[] = new Step(__('Rename the reseller settings'), 'v-rename-package-tenancy', [$current->name, $newName]);
        }

        return $steps;
    }

    /** @return list<Step> */
    public function planCopy(PackageRecord $current, string $newName): array
    {
        return [
            new Step(sprintf(__('Copy package %s'), $current->name), 'v-copy-user-package', [$current->name, $newName]),
            ...$this->tenancySteps($newName, [
                'USERS' => $current->get('USERS', '0'),
                'RESELLER_PACKAGES' => $current->get('RESELLER_PACKAGES'),
                'RESELLER_VISIBLE' => $current->get('RESELLER_VISIBLE', 'no'),
            ], null),
            ...$this->portsStep($newName, $current->get('PORTS', '0'), null),
        ];
    }

    /** @return list<Step> */
    public function planDelete(PackageRecord $current, string $reseller = ''): array
    {
        if ($reseller !== '') {
            return [new Step(sprintf(__('Delete package %s'), $current->name), 'v-delete-reseller-package', [$reseller, $current->name])];
        }

        return [
            new Step(sprintf(__('Delete package %s'), $current->name), 'v-delete-user-package', [$current->name]),
            new Step(__('Delete the reseller settings'), 'v-delete-package-tenancy', [$current->name]),
        ];
    }

    public function exists(string $name): bool
    {
        return $this->arc->run('v-list-user-package', [$name, 'json'], Options::allowFailure())->ok();
    }

    /**
     * PORTS of the package sidecar (E12) when it is set or changed. The
     * command checks a reseller's package against the reseller's PORTS.
     *
     * @return list<Step>
     */
    private function portsStep(string $name, ?string $ports, ?PackageRecord $current): array
    {
        if ($ports === null || $ports === ($current?->get('PORTS', '0') ?? '0')) {
            return [];
        }

        return [new Step(sprintf(__('Set %s'), 'PORTS'), 'v-change-package-tenancy', [$name, 'PORTS', $ports])];
    }

    /**
     * @param  array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string}|null  $tenancy
     * @return list<Step>
     */
    private function tenancySteps(string $name, ?array $tenancy, ?PackageRecord $current): array
    {
        if ($tenancy === null) {
            return [];
        }
        $steps = [];
        foreach ($tenancy as $key => $value) {
            $old = $current?->get($key, $key === 'USERS' ? '0' : ($key === 'RESELLER_VISIBLE' ? 'no' : ''));
            if ($current === null ? ($value !== '' && $value !== '0' && $value !== 'no') : $value !== $old) {
                $steps[] = new Step(sprintf(__('Set %s'), $key), 'v-change-package-tenancy', [$name, $key, $value]);
            }
        }

        return $steps;
    }
}
