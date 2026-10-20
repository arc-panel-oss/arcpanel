<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Services\WebDomains\Step;

/**
 * The packages of the panel (web/list/updates, E7 batch 3 spec §10).
 * Updates themselves run as the system task; the automatic updates are the
 * policy of the Update Center (UpdateCenter, E33 phase 1).
 */
final class PanelUpdates
{
    /** Packages v-update-sys-arcpanel accepts. */
    public const PACKAGES = ['arcpanel', 'arcpanel-nginx', 'arcpanel-php'];

    public function __construct(private readonly ArcCommand $arc) {}

    /** @return list<array{name: string, version: string, arch: string, upToDate: bool, description: string, date: string}> */
    public function packages(): array
    {
        $packages = [];
        foreach ($this->arc->json('v-list-sys-arcpanel-updates', [], Options::allowFailure()) as $name => $row) {
            if (is_array($row)) {
                $packages[] = [
                    'name' => (string) $name,
                    'version' => (string) ($row['VERSION'] ?? ''),
                    'arch' => (string) ($row['ARCH'] ?? ''),
                    'upToDate' => ($row['UPDATED'] ?? '') === 'yes',
                    'description' => (string) ($row['DESCR'] ?? ''),
                    'date' => trim(($row['DATE'] ?? '').' '.($row['TIME'] ?? '')),
                ];
            }
        }

        return $packages;
    }

    /**
     * The update of one package, or of every package without one, as the
     * start of the system task (API v2, E34 batch 6 §5.4).
     */
    public static function planRun(?string $package): Step
    {
        return $package === null
            ? SystemTaskService::step(__('Update every package'), 'arcpanel-update')
            : SystemTaskService::step(sprintf(__('Update %s'), $package), 'arcpanel-update', [$package]);
    }
}
