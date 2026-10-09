<?php

namespace App\Http\Controllers\Files;

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Policies\FilePolicy;
use App\Services\Backups\BackupService;
use App\Services\Files\FileEntry;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use App\Services\Files\UploadSessions;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The file manager page of an account (E3 spec §7.1): renders the first page
 * of the folder on the server too and mounts the file-manager island, which
 * uses FileController and FileTransferController.
 */
final class FilePageController extends Controller
{
    /** Strings of the island, translated for the page (tests/Unit/FilePagesTest.php checks the list) */
    public const STRINGS = [
        '%d items', '%d items already exist in the destination.', '%d items copied.', '%d items cut; paste them in another folder.',
        '%d items have been deleted.', '%d results for "%s"', '%d selected', '%s already exists here.', '%s already exists in the destination.',
        '%s has been deleted.', '%s has been extracted.', '%s has been saved.', 'A file with this name exists', 'Actions', 'Actions for %s',
        'and %d more', 'Apache reads .htaccess on the next request; no restart is needed. NGINX-only domains ignore it.', 'Apply', 'Apply to all',
        'Apply to everything inside the folder', 'Archive', 'Archive name', 'Audio', 'Back to the backups', 'Back to the folder', 'Calculate size', 'Cancel', 'Cancel %s',
        'Cancel all', 'Cancelled', 'Close', 'Close search', 'Compress', 'Compress to zip', 'Content of %s', 'Contents of ~/%s', 'Copied to ~/%s',
        'Copy', 'Copy here', 'Copy to', 'Copy to…', 'Copy, cut, paste', 'Counting…', 'Create', 'Current folder', 'Cut', 'Delete',
        'Delete %d items? This cannot be undone.', 'Delete %s? This cannot be undone.', 'Destination folder', 'Discard changes', 'Disk %s of %s',
        'Disk %s used', 'Disk used', 'Done', 'Download', 'Download as zip', 'Drop files anywhere on the list to upload them here.',
        'Drop files here or use Upload.', 'Drop to upload', 'Edit', 'Enter a valid name: no slash, not . or ..', 'Execute', 'Extract', 'Extract here',
        'Extract to a new folder', 'File', 'File mode', 'File name', 'Files', 'Files and folders go into ~/%s', 'Find', 'Folder', 'Folder mode',
        'Folder name', 'Folders', 'Folders get the folder mode, files the file mode. Links are left as they are.', 'Grid', 'Group', 'Hidden files',
        'Home folder of %s', 'Image', 'Into', 'Keep both', 'Keep editing', 'Keyboard shortcuts', 'Larger than the limit of %s',
        'Leave without saving?', 'Line %d, column %d', 'Link to', 'List', 'Loading…', 'Mode', 'Modified', 'Move here', 'Move the selection',
        'Move to', 'Move to…', 'Moved to ~/%s', 'Name', 'New file', 'New folder', 'New name', 'Next', 'No folders here.', 'Nothing found.',
        'Only the first results are shown; narrow your search.', 'Open', 'Others', 'outside home', 'Overwrite', 'Owner', 'Paste', 'Path',
        'PDF document', 'Permissions', 'Permissions of %d items', 'Permissions of %s', 'Plain text', 'Preview', 'Previous', 'Progress of %s', 'Read',
        'Read-only: changes are disabled.', 'Refresh', 'Reload', 'Rename', 'Restore', 'Restore %s from the snapshot? The current file is overwritten.', 'Resume', 'Save', 'Save in the editor', 'Saving…', 'Search in this folder',
        'Search results', 'Select %s', 'Select a file or folder to see its details.', 'Select all', 'Selection details', 'Shortcuts', 'Size', 'Skip',
        'Snapshot %s · %s · read-only', 'Some items already exist', 'Someone saved %s after you opened it. Reload to see their version, or overwrite it with yours.',
        'Something went wrong', 'Source code', 'Text', 'The archive name ends with .zip.', 'The file changed on disk',
        'The search took too long; narrow your search.', 'This file is not UTF-8 text. It is shown read-only so nothing is changed by accident.',
        'This file is too large to edit; download it instead.', 'This folder is empty.',
        'This link points outside the home directory and cannot be opened.', 'This name is not valid UTF-8; rename it over SSH or FTP.',
        'Unsaved changes', 'Up one folder', 'Upload', 'Upload a folder', 'Upload files', 'Upload progress', 'Uploaded', 'Uploaded as %s', 'Uploads',
        'Video', 'View', 'Waiting', 'Who', 'Wrap lines', 'Write', 'You cannot open this folder.', 'Your changes to %s are not saved.',
        'A folder cannot go inside itself.', 'Could not copy', 'Could not move', 'Read-only folder', 'The items are already in this folder.',
        'This folder is read-only. Open a folder inside it, such as public_html.',
        'This folder is read-only: you can open, copy and download what is in it, but not add, move, rename or delete. Your site files go in public_html.',
    ];

    public function __construct(
        private readonly FileService $files,
        private readonly SysConfig $config,
        private readonly TenancyDirectory $tenancy,
    ) {}

    /** /files: the effective account. */
    public function entry(Request $request, Principal $principal): RedirectResponse
    {
        $query = array_filter(['path' => $request->query('path')], fn ($value) => is_string($value) && $value !== '');

        return redirect()->route('files.show', ['user' => $principal->effectiveUser(), ...$query]);
    }

    public function show(Request $request, string $user, Principal $principal, FilePolicy $policy): View|RedirectResponse
    {
        if ($request->query->has('snapshot')) {
            return $this->snapshot($request, $user, $principal);
        }
        abort_unless($policy->view($principal, $user), 403);
        $path = FilePath::from(is_string($request->query('path')) ? $request->query('path') : '');
        $edit = is_string($request->query('edit')) && $request->query('edit') !== '' ? FilePath::from($request->query('edit')) : null;

        try {
            $listing = $this->files->list($user, $path);
            $dir = $this->files->info($user, $path);
        } catch (ArcCommandException $e) {
            // A folder the account cannot read (~/mail): back to its parent
            if ($e->exit() === ExitCode::Forbidden && ! $path->isRoot()) {
                return redirect()->route('files.show', array_filter(['user' => $user, 'path' => $path->parent()->relative()], fn ($v) => $v !== ''))
                    ->with('error', __('You cannot open this folder.'));
            }
            if ($e->exit() !== ExitCode::NotExist) {
                throw $e;
            }
            // A link to a file opens its folder with the file in the editor
            $entry = $path->isRoot() ? null : $this->files->find($user, $path);
            abort_if($entry === null || $entry->isDirectory(), 404);

            return redirect()->route('files.show', ['user' => $user, 'path' => $path->parent()->relative(), 'edit' => $path->relative()]);
        }
        $account = $this->files->account($user);
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }

        return view('files.show', [
            'user' => $user,
            'path' => $path,
            'listing' => $listing,
            'foreign' => $user !== $principal->effectiveUser(),
            'owner' => $this->tenancy->ownerOf($user),
            'home' => $account->home,
            'suspended' => $account->suspended,
            'props' => [
                'user' => $user,
                'page' => route('files.show', ['user' => $user]),
                'base' => route('files.show', ['user' => $user]).'/api',
                'abilities' => $policy->abilities($principal, $user),
                'readOnly' => $principal->isReadOnly(),
                'limits' => [
                    'uploadMax' => UploadSessions::maxBytes($this->config->get('FILE_MANAGER_UPLOAD_MAX')),
                    'chunkSize' => UploadSessions::CHUNK,
                    'editMax' => FileService::EDIT_MAX,
                ],
                'quota' => $account->quota(),
                'listing' => [
                    'path' => $path->relative(),
                    'dir' => $dir->toArray(),
                    'total' => $listing['total'],
                    'offset' => $listing['offset'],
                    'entries' => array_map(fn (FileEntry $e) => $e->toArray(), $listing['entries']),
                ],
                'edit' => $edit?->relative(),
                'strings' => $strings,
            ],
        ]);
    }

    /**
     * Snapshot mode (E7 batch 1 spec §10.3): a folder of a restic snapshot,
     * read-only, with "Restore" when the principal may restore backups of
     * the account. The backup policy decides, not the file policy: restoring
     * is a backup action.
     */
    private function snapshot(Request $request, string $user, Principal $principal): View
    {
        $policy = app(BackupPolicy::class);
        $backups = app(BackupService::class);
        abort_unless($policy->view($principal, $user), 403);
        abort_unless($backups->incremental($user), 404);
        $id = (string) $request->query('snapshot');
        abort_unless(preg_match('/^[0-9a-f]{8,64}$/', $id) === 1, 404);
        $snapshot = $backups->snapshot($user, $id);

        $path = FilePath::from(is_string($request->query('path')) ? $request->query('path') : '');
        $listing = $backups->snapshotFolder($user, $snapshot->shortId, $path->absolute('/home/'.$user));
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = __($key);
        }

        return view('files.snapshot', [
            'user' => $user,
            'path' => $path,
            'snapshot' => $snapshot,
            'entries' => $listing->entries,
            'props' => [
                'user' => $user,
                'page' => route('files.show', ['user' => $user]),
                'base' => route('files.show', ['user' => $user]).'/api',
                'abilities' => ['view' => true, 'manage' => false],
                'readOnly' => true,
                'snapshot' => [
                    'id' => $snapshot->shortId,
                    'time' => $snapshot->label(),
                    'back' => route('backups.snapshots.show', ['user' => $user, 'snapshot' => $snapshot->shortId]),
                    'restore' => $policy->restore($principal, $user),
                ],
                'limits' => ['uploadMax' => 0, 'chunkSize' => UploadSessions::CHUNK, 'editMax' => FileService::EDIT_MAX],
                'quota' => ['used' => 0, 'quota' => null],
                'listing' => [
                    'path' => $path->relative(),
                    'dir' => ['name' => $path->name(), 'type' => 'd', 'dir' => true, 'writable' => false],
                    'total' => count($listing->entries),
                    'offset' => 0,
                    'entries' => $listing->entries,
                ],
                'edit' => null,
                'strings' => $strings,
            ],
        ]);
    }
}
