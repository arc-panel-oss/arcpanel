<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The automatic update policy of the panel (`v-list-sys-update-policy json`,
 * E33 phase 1 spec §7.1, §7.2), with the installed version and the state of
 * the release manifest.
 *
 * @property array{policy: string, windowDays: list<string>, windowHours: string, delayDays: int, paused: ?string, nextWindow: ?string, version: string, line: string, channel: string, manifestChecked: ?string, manifestGenerated: ?string, manifestError: ?string} $resource
 */
final class UpdatePolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $policy = $this->resource;
        $next = $policy['nextWindow'] !== null ? str_replace(' ', 'T', $policy['nextWindow']).':00' : null;

        return [
            /** @var string `off` (nothing is checked), `notify` (new releases are announced) or `patch` (patch releases of the installed line are installed in the window) */
            'policy' => $policy['policy'],
            /** @var list<string> the days of the window, `mon` … `sun` */
            'window_days' => $policy['windowDays'],
            /** @var string the hours of the window, `HH-HH` in the server's time zone; it may wrap past midnight */
            'window_hours' => $policy['windowHours'],
            /** @var int the days a release waits before the policy installs it; security releases do not wait */
            'delay_days' => $policy['delayDays'],
            /** @var bool automatic updates stopped after a failed update; resumeAutoUpdates starts them again */
            'paused' => $policy['paused'] !== null,
            /** @var string|null why automatic updates stopped */
            'paused_reason' => $policy['paused'],
            /** @var string|null the start of the current or next window in the server's time zone, with the policy `patch` */
            'next_window' => $next,
            'installed' => [
                /** @var string the installed version of the panel */
                'version' => $policy['version'],
                /** @var string its release line */
                'line' => $policy['line'],
            ],
            'manifest' => [
                /** @var string `stable` or `beta`, from the APT source of the server */
                'channel' => $policy['channel'],
                /** @var string|null the last verified download, in the server's time zone */
                'checked_at' => $policy['manifestChecked'] !== null ? str_replace(' ', 'T', $policy['manifestChecked']) : null,
                /** @var string|null when the stored manifest was generated (UTC) */
                'generated_at' => $policy['manifestGenerated'],
                /** @var string|null why the last download failed; the stored manifest is still used */
                'error' => $policy['manifestError'],
            ],
        ];
    }
}
