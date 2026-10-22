<?php

namespace App\Http\Controllers\Server\Backups;

use App\Auth\Principal;
use App\Auth\SudoMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\BackupDestinationRequest;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\DestinationService;
use App\Services\Backups\JobService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backup destinations of the Backups hub (E48 spec §4, §8.2): the table,
 * the wizard that adds one, the edit form with write-only secrets, the
 * connection test with SFTP host key confirmation, and the recovery kit.
 * Administrators only.
 */
final class DestinationController extends Controller
{
    public function __construct(
        private readonly DestinationService $destinations,
        private readonly JobService $jobs,
    ) {}

    public function index(Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewDestinations($principal), 403);

        return view('server.backups.destinations.index', [
            'destinations' => $this->destinations->all(),
            'jobs' => $this->jobs->all(),
            'canManage' => $policy->manageDestinations($principal),
        ]);
    }

    public function create(Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->manageDestinations($principal), 403);

        return view('server.backups.destinations.form', ['destination' => null]);
    }

    public function store(BackupDestinationRequest $request): RedirectResponse
    {
        $name = (string) $request->input('name');
        $result = $this->destinations->add($name, $request->driver(), $request->fields(), $request->secrets());
        if (! $result->ok()) {
            return back()->withInput($request->except('secrets'))->with('error', $result->errorMessage());
        }
        $check = $this->destinations->check($name);

        return redirect()->route('server.backups.destinations.edit', ['destination' => $name])
            ->with('ok', sprintf(__('Destination %s has been added.'), $name))
            ->with('check', ['name' => $name] + $check);
    }

    public function edit(string $destination, Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewDestinations($principal), 403);
        $record = $this->destinations->find($destination);

        return view('server.backups.destinations.form', [
            'destination' => $record,
            'jobs' => array_filter($this->jobs->all(), fn ($job) => in_array($record->name, $job->destinations(), true)),
            'canManage' => $policy->manageDestinations($principal),
        ]);
    }

    public function update(BackupDestinationRequest $request, string $destination): RedirectResponse
    {
        $result = $this->destinations->change($destination, $request->fields(), $request->secrets());
        if (! $result->ok()) {
            return back()->withInput($request->except('secrets'))->with('error', $result->errorMessage());
        }

        return redirect()->route('server.backups.destinations.edit', ['destination' => $destination])
            ->with('ok', sprintf(__('Destination %s has been saved.'), $destination))
            ->with('check', ['name' => $destination] + $this->destinations->check($destination));
    }

    public function destroy(string $destination, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageDestinations($principal), 403);
        $this->destinations->find($destination);
        $result = $this->destinations->delete($destination);
        if (! $result->ok()) {
            return back()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.backups.destinations')->with('ok', sprintf(__('Destination %s has been deleted.'), $destination));
    }

    /** Test the connection; ACCEPT carries the SFTP host key the administrator confirmed. */
    public function check(Request $request, string $destination, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageDestinations($principal), 403);
        $this->destinations->find($destination);
        $accept = (string) $request->input('accept', '');
        abort_unless($accept === '' || preg_match('/^SHA256:[A-Za-z0-9+\/=]{20,64}$/', $accept) === 1, 422);

        return back()->with('check', ['name' => $destination] + $this->destinations->check($destination, $accept));
    }

    /**
     * The recovery kit as a file. It holds the destination's private key, so
     * the password must have been confirmed in the last minutes.
     */
    public function kit(Request $request, string $destination, Principal $principal, BackupPolicy $policy, SudoMode $sudo): Response
    {
        abort_unless($policy->manageDestinations($principal), 403);
        $record = $this->destinations->find($destination);
        abort_unless($record->encrypt, 404);
        if (! $sudo->active()) {
            return $sudo->challenge($request);
        }
        $result = $this->destinations->recoveryKit($destination);
        if (! $result->ok()) {
            return back()->with('error', $result->errorMessage());
        }

        return response($result->stdout, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="arcpanel-recovery-kit-'.$destination.'.txt"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, array{title: string, description: string, icon: string}> */
    public static function driverCards(): array
    {
        $descriptions = [
            'local' => __('A folder on this server or on a disk mounted on it.'),
            'sftp' => __('Another server over SSH, with a password or a key.'),
            'ftp' => __('An FTP server, with explicit or implicit TLS.'),
            's3' => __('Amazon S3, Cloudflare R2, Wasabi, MinIO or another S3-compatible store.'),
            'b2' => __('A Backblaze B2 bucket.'),
            'gdrive' => __('A Google Drive folder, with an account token or a service account.'),
            'dropbox' => __('A Dropbox folder.'),
            'onedrive' => __('OneDrive or a SharePoint library.'),
            'webdav' => __('Nextcloud, ownCloud or another WebDAV server.'),
            'rclone' => __('Any other rclone remote, from its configuration.'),
        ];
        $cards = [];
        foreach (array_keys(BackupDestination::DRIVERS) as $driver) {
            $cards[$driver] = ['title' => BackupDestination::driverLabel($driver), 'description' => $descriptions[$driver], 'icon' => BackupDestination::driverIcon($driver)];
        }

        return $cards;
    }
}
