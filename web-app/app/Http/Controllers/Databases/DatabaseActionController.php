<?php

namespace App\Http\Controllers\Databases;

use App\Auth\ClientIp;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Concerns\ChecksBulkItems;
use App\Http\Controllers\Controller;
use App\Policies\DatabasePolicy;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\DbAdminLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Single and bulk actions on databases (web/bulk/db, web/suspend/db,
 * web/delete/db, web/download/database) and phpMyAdmin single sign-on.
 */
final class DatabaseActionController extends Controller
{
    use ChecksBulkItems;

    public function __construct(private readonly DatabaseService $databases) {}

    public function suspend(string $user, string $database, Principal $principal, DatabasePolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $user, $database, $policy->suspend($principal, $user));
    }

    public function unsuspend(string $user, string $database, Principal $principal, DatabasePolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $user, $database, $policy->suspend($principal, $user));
    }

    public function destroy(string $user, string $database, Principal $principal, DatabasePolicy $policy): RedirectResponse
    {
        return $this->single('delete', $user, $database, $policy->delete($principal, $user));
    }

    /** POST /app/db/bulk {action, items[]} with items "user/database". */
    public function bulk(Request $request, Principal $principal, DatabasePolicy $policy, OwnerScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(DatabaseService::BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'max:200', 'regex:/^[[:alnum:]][-.\w]*\/[\w-]+$/'],
        ]);
        $action = $data['action'];
        $items = $this->bulkItems($data['items'], 2, $scope, fn (string $owner) => $policy->action($action, $principal, $owner));

        return $this->databases->bulk($action, $items)
            ->flash(redirect()->route('db.index', array_filter(['owner' => $request->query('owner')])));
    }

    /** A gzip dump of the database (web/download/database), served by NGINX from the backup directory. */
    public function download(string $user, string $database, Principal $principal, DatabasePolicy $policy): Response|RedirectResponse
    {
        abort_unless($policy->view($principal, $user) && ! $principal->isReadOnly(), 403);
        $this->databases->find($user, $database);

        $path = $this->databases->dump($user, $database);
        if (preg_match('#^/backup/[\w.-]+$#', $path) !== 1) {
            return back()->with('error', __('The database could not be exported.'));
        }

        $name = basename($path);

        return response('', 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Accel-Redirect' => $path,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Single sign-on into phpMyAdmin (templates/pages/list_db.php, install/deb/phpmyadmin/arcpanel-sso.php). */
    public function phpMyAdmin(Request $request, string $user, string $database, Principal $principal, DatabasePolicy $policy, DbAdminLinks $links): RedirectResponse
    {
        abort_unless($policy->update($principal, $user) && $links->ssoAvailable(), 403);
        $record = $this->databases->find($user, $database);
        abort_unless($record->type === 'mysql' && ! $record->suspended, 404);

        return redirect()->away($links->ssoUrl($record, ClientIp::of($request)));
    }

    private function single(string $action, string $user, string $database, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $this->databases->find($user, $database);

        $result = $this->databases->bulk($action, [[$user, $database]]);
        $back = $action === 'delete'
            ? redirect()->route('db.index')
            : redirect()->back(fallback: route('db.index'));

        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        return $back->with('ok', match ($action) {
            'suspend' => sprintf(__('Database %s has been suspended.'), $database),
            'unsuspend' => sprintf(__('Database %s has been unsuspended.'), $database),
            default => sprintf(__('Database %s has been deleted.'), $database),
        });
    }
}
