<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;

/**
 * The packages of the panel and their automatic updates (web/list/updates,
 * web/add|delete/cron/autoupdate), E7 batch 3 spec §10. Updates themselves
 * run as the system task.
 */
final class PanelUpdates
{
    /** Packages v-update-sys-hestia accepts. */
    public const PACKAGES = ['arcpanel', 'arcpanel-nginx', 'arcpanel-php', 'hestia', 'hestia-nginx', 'hestia-php'];

    public function __construct(private readonly ArcCommand $arc) {}

    /** @return list<array{name: string, version: string, arch: string, upToDate: bool, description: string, date: string}> */
    public function packages(): array
    {
        $packages = [];
        foreach ($this->arc->json('v-list-sys-hestia-updates', [], Options::allowFailure()) as $name => $row) {
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

    public function autoUpdate(): bool
    {
        return trim($this->arc->run('v-list-sys-hestia-autoupdate', ['plain'], Options::allowFailure())->stdout) === 'Enabled';
    }

    /** `v-add-cron-hestia-autoupdate` or `v-delete-cron-hestia-autoupdate` */
    public function setAutoUpdate(bool $on): CommandResult
    {
        return $this->arc->run($on ? 'v-add-cron-hestia-autoupdate' : 'v-delete-cron-hestia-autoupdate', [], Options::allowFailure());
    }
}
