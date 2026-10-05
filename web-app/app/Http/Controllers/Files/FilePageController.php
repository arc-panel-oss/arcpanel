<?php

namespace App\Http\Controllers\Files;

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FilePolicy;
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
        'Apply to everything inside the folder', 'Archive', 'Archive name', 'Audio', 'Back to the folder', 'Calculate size', 'Cancel', 'Cancel %s',
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
        'Read-only: changes are disabled.', 'Refresh', 'Reload', 'Rename', 'Resume', 'Save', 'Save in the editor', 'Saving…', 'Search in this folder',
        'Search results', 'Select %s', 'Select a file or folder to see its details.', 'Select all', 'Selection details', 'Shortcuts', 'Size', 'Skip',
        'Some items already exist', 'Someone saved %s after you opened it. Reload to see their version, or overwrite it with yours.',
        'Something went wrong', 'Source code', 'Text', 'The archive name ends with .zip.', 'The file changed on disk',
        'The search took too long; narrow your search.', 'This file is not UTF-8 text. It is shown read-only so nothing is changed by accident.',
        'This file is too large to edit; download it instead.', 'This folder is empty.',
        'This link points outside the home directory and cannot be opened.', 'This name is not valid UTF-8; rename it over SSH or FTP.',
        'Unsaved changes', 'Up one folder', 'Upload', 'Upload a folder', 'Upload files', 'Upload progress', 'Uploaded', 'Uploaded as %s', 'Uploads',
        'Video', 'View', 'Waiting', 'Who', 'Wrap lines', 'Write', 'You cannot change this folder.', 'Your changes to %s are not saved.',
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
        abort_unless($policy->view($principal, $user), 403);
        $path = FilePath::from(is_string($request->query('path')) ? $request->query('path') : '');
        $edit = is_string($request->query('edit')) && $request->query('edit') !== '' ? FilePath::from($request->query('edit')) : null;

        try {
            $listing = $this->files->list($user, $path);
            $dir = $this->files->info($user, $path);
        } catch (ArcCommandException $e) {
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
}
