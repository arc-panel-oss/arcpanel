<?php

namespace App\Audit;

use Illuminate\Http\Request;

/**
 * Routes whose audit action is not their route name (E23 spec §5.2): the
 * explicit events that need nothing from the controller, and the sensitive
 * reads of D4, which are recorded although they are GET requests.
 */
final class RouteActions
{
    /** Route name => audit action. */
    public const ACTIONS = [
        'logout' => 'auth.logout',
        'access-keys.store' => 'access-keys.create',
        'access-keys.destroy' => 'access-keys.delete',
        'access-keys.bulk' => 'access-keys.delete',
        'api.v2.access-keys.store' => 'access-keys.create',
        'api.v2.access-keys.destroy' => 'access-keys.delete',
        'db.download' => 'db.dump',
        'api.v2.databases.dump' => 'db.dump',
        'api.v2.files.download' => 'files.download',
        // Sensitive reads (D4)
        'backups.archives.download' => 'backups.download',
        'api.v2.backups.download' => 'backups.download',
        'files.download' => 'files.download',
        'app-processes.api.env' => 'secrets.reveal',
        'docker.api.apps.env' => 'secrets.reveal',
        'node.api.apps.env' => 'secrets.reveal',
        'api.v2.app-processes.env.show' => 'secrets.reveal',
        'api.v2.node-apps.env.show' => 'secrets.reveal',
    ];

    /** GET routes recorded as sensitive reads. */
    public const READS = [
        'backups.archives.download',
        'api.v2.backups.download',
        'files.download',
        'app-processes.api.env',
        'docker.api.apps.env',
        'node.api.apps.env',
        'api.v2.app-processes.env.show',
        'api.v2.node-apps.env.show',
    ];

    /** Reads that reveal values only with `reveal=1` (D41 of E34). */
    private const REVEAL_ONLY = ['api.v2.app-processes.env.show', 'api.v2.node-apps.env.show'];

    public static function action(?string $routeName): string
    {
        return $routeName === null ? 'http.unrouted' : (self::ACTIONS[$routeName] ?? $routeName);
    }

    /** Whether a GET, HEAD or OPTIONS request is a sensitive read. */
    public static function isSensitiveRead(Request $request): bool
    {
        $name = $request->route()?->getName();
        if ($name === null || ! in_array($name, self::READS, true) || ! $request->isMethod('GET')) {
            return false;
        }

        return ! in_array($name, self::REVEAL_ONLY, true) || $request->boolean('reveal');
    }
}
