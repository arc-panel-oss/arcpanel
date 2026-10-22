<?php

namespace App\Http\Controllers\Server\Backups;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\BackupHubSettingsRequest;
use App\Policies\BackupPolicy;
use App\Policies\ServerPolicy;
use App\Services\Server\ServerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Settings of the Backups hub (E48 spec §8.2): compression, temporary
 * folder, backups at once, load limit, the report mail and suspended users.
 * The server settings capability decides, as for the other settings.
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly ServerSettings $settings) {}

    public function show(Principal $principal, BackupPolicy $backups, ServerPolicy $policy): View
    {
        abort_unless($backups->viewHub($principal) && $policy->viewSettings($principal), 403);

        return view('server.backups.settings', [
            'config' => $this->settings->config(),
            'canEdit' => $policy->editSettings($principal),
        ]);
    }

    public function update(BackupHubSettingsRequest $request): RedirectResponse
    {
        $result = $this->settings->apply($this->settings->planBackupHub($request->values()));
        $redirect = redirect()->route('server.backups.settings');
        if (! $result->ok()) {
            return $redirect->withInput()
                ->with('error', sprintf(__('%s failed'), $result->failed?->label ?? ''))
                ->with('error_detail', $result->error);
        }

        return $redirect->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }
}
