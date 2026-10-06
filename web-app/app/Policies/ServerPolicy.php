<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\Principal;

/**
 * Server health (dashboard widgets, E1-S2), the server graphs (E7 batch 2)
 * and the server pages of E7 batch 3 (spec §5.2): services, settings,
 * config editors and updates, administrators only.
 */
final class ServerPolicy
{
    /** Services the panel itself runs on: restart only (spec D5). */
    private const PROTECTED_SERVICES = ['hestia'];

    public function __construct(private readonly SysConfig $config) {}

    public function viewHealth(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }

    /** The Task Monitor page and the graph data (E7 batch 2 spec §9.5). */
    public function viewGraphs(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.rrd');
    }

    /** The services page; POLICY_SYSTEM_HIDE_SERVICES keeps it for the ROOT_USER. */
    public function viewServices(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.services')
            && (! $this->config->is('POLICY_SYSTEM_HIDE_SERVICES') || $principal->loginUser === $this->config->get('ROOT_USER'));
    }

    public function manageServices(Principal $principal): bool
    {
        return $this->viewServices($principal) && ! $principal->isReadOnly();
    }

    public function stopService(Principal $principal, string $service): bool
    {
        return $this->manageServices($principal) && ! self::isProtectedService($service);
    }

    public static function isProtectedService(string $service): bool
    {
        return in_array($service, self::PROTECTED_SERVICES, true);
    }

    public function viewSettings(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.settings');
    }

    public function editSettings(Principal $principal): bool
    {
        return $this->viewSettings($principal) && ! $principal->isReadOnly();
    }

    /** The System Protection group of the Security tab: the ROOT_USER only. */
    public function protectAdmin(Principal $principal): bool
    {
        return $this->editSettings($principal) && $principal->loginUser === $this->config->get('ROOT_USER');
    }

    public function viewConfig(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.config');
    }

    public function editConfig(Principal $principal): bool
    {
        return $this->viewConfig($principal) && ! $principal->isReadOnly();
    }

    public function viewUpdates(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.updates');
    }

    public function manageUpdates(Principal $principal): bool
    {
        return $this->viewUpdates($principal) && ! $principal->isReadOnly();
    }
}
