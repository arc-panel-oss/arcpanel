<?php

namespace App\Http\Controllers\Backups;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupService;
use App\Services\Support\OwnerList;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The restore wizard of an account (E48 spec §8.1): choose an archive or a
 * snapshot, choose what to restore, confirm. The restore itself is queued by
 * the archive and snapshot restore routes, as before.
 */
final class RestoreController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SysConfig $config,
    ) {}

    public function __invoke(Request $request, string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->restore($principal, $user), 403);
        $data = $request->validate([
            'archive' => ['nullable', 'string', 'max:255'],
            'snapshot' => ['nullable', 'string', 'regex:/^[0-9a-f]{8,64}$/'],
        ]);
        $incremental = $this->backups->incremental($user);
        $archive = null;
        $snapshot = null;
        $content = null;
        if (($data['archive'] ?? '') !== '') {
            $archive = $this->backups->archive($user, $data['archive']);
        } elseif (($data['snapshot'] ?? '') !== '') {
            abort_unless($incremental, 404);
            $snapshot = $this->backups->snapshot($user, $data['snapshot']);
            $content = $this->backups->snapshotContent($user, $snapshot->shortId);
        }

        return view('backups.restore', [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user),
            'incremental' => $incremental,
            'archives' => $this->backups->archives($user),
            'snapshots' => $incremental ? $this->backups->snapshots($user) : [],
            'archive' => $archive,
            'snapshot' => $snapshot,
            'items' => $archive?->items ?? $content?->items,
            'systems' => ['dns' => $this->config->get('DNS_SYSTEM') !== '', 'mail' => $this->config->get('MAIL_SYSTEM') !== ''],
        ]);
    }

    /**
     * Folders of the home directory split into visible ones and dotfiles,
     * which the wizard folds into "Hidden files".
     *
     * @param  list<string>  $folders
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function splitHidden(array $folders): array
    {
        $hidden = array_values(array_filter($folders, fn (string $f) => str_starts_with(basename($f), '.')));

        return [array_values(array_diff($folders, $hidden)), $hidden];
    }

    /** Section labels of a backup, without DNS or mail on a server without them unless the backup holds some. */
    public static function sections(array $items, array $systems, bool $snapshot): array
    {
        $sections = ['web' => __('Web Domains'), 'dns' => __('DNS Domains'), 'mail' => __('Mail Domains'), 'db' => __('Databases'), 'cron' => __('Cron Jobs')];
        if (! $snapshot) {
            $sections['udir'] = __('User Directories');
        }

        return array_filter($sections, fn ($type) => ($systems[$type] ?? true) || ($items[$type] ?? []) !== [], ARRAY_FILTER_USE_KEY);
    }
}
