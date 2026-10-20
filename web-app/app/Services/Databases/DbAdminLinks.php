<?php

namespace App\Services\Databases;

use App\Arc\SysConfig;
use Illuminate\Http\Request;

/**
 * phpMyAdmin and phpPgAdmin links of the database pages
 * (templates/pages/list_db.php), under /phpmyadmin/, /phppgadmin/ or the
 * DB_PMA_ALIAS / DB_PGA_ALIAS aliases: on the database tools port of the
 * panel host when DB_ADMIN_PORT is set (E46), which also answers by IP
 * address; else on the web server of the panel's host name, which does not.
 */
final class DbAdminLinks
{
    public function __construct(
        private readonly SysConfig $config,
        private readonly Request $request,
    ) {}

    /** The panel was opened by IP address (ipUsed() in web/inc/main.php). */
    public function ipUsed(): bool
    {
        return filter_var(trim($this->request->getHost(), '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /** The tools answer on the database tools port (E46). */
    public function onPort(): bool
    {
        return $this->config->get('DB_ADMIN_PORT') !== '';
    }

    /** The links would not work: the panel was opened by IP and the tools have no port. */
    public function unreachable(): bool
    {
        return ! $this->onPort() && $this->ipUsed();
    }

    /** "//host/alias/" of a database type. */
    public function base(string $type): string
    {
        $alias = $type === 'pgsql'
            ? ($this->config->get('DB_PGA_ALIAS') ?: 'phppgadmin')
            : ($this->config->get('DB_PMA_ALIAS') ?: 'phpmyadmin');

        $host = $this->request->getHost().($this->onPort() ? ':'.$this->config->get('DB_ADMIN_PORT') : '');

        return '//'.$host.'/'.trim($alias, '/').'/';
    }

    /** The link of the "Open phpMyAdmin" hint after a database was added. */
    public function adminUrl(string $type): string
    {
        return 'https:'.$this->base($type);
    }

    /**
     * Toolbar buttons of the list for the server's database types.
     *
     * @param  list<string>  $types
     * @return list<array{label: string, url: string, disabled: bool}>
     */
    public function toolbar(array $types): array
    {
        $buttons = [];
        if (in_array('mysql', $types, true)) {
            $buttons[] = ['label' => 'phpMyAdmin', 'url' => $this->base('mysql'), 'disabled' => $this->unreachable()];
        }
        if (in_array('pgsql', $types, true)) {
            $buttons[] = ['label' => 'phpPgAdmin', 'url' => $this->base('pgsql'), 'disabled' => $this->unreachable()];
        }

        return $buttons;
    }

    /** Single sign-on into phpMyAdmin needs PHPMYADMIN_KEY (v-add-sys-pma-sso) and a reachable phpMyAdmin. */
    public function ssoAvailable(): bool
    {
        return $this->config->get('PHPMYADMIN_KEY') !== '' && ! $this->unreachable();
    }

    /**
     * The arcpanel-sso.php sign-on of templates/pages/list_db.php: a token over
     * the database, its owner, the client IP and the time, valid for a minute.
     * The fields are posted by a form, never sent in a query string, so the
     * token reaches no access log or browser history (E39 W2 spec D9).
     *
     * @return array{action: string, fields: array<string, string>}
     */
    public function ssoForm(DatabaseRecord $record, string $clientIp): array
    {
        $time = time();
        $token = password_hash($record->database.$record->user.$clientIp.$time.$this->config->get('PHPMYADMIN_KEY'), PASSWORD_DEFAULT);

        return [
            'action' => 'https:'.$this->base('mysql').'arcpanel-sso.php',
            'fields' => [
                'host' => $record->host,
                'database' => $record->database,
                'user' => $record->user,
                'exp' => (string) $time,
                'arcpanel_token' => $token,
            ],
        ];
    }
}
