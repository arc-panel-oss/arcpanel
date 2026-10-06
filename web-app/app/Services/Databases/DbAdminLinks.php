<?php

namespace App\Services\Databases;

use App\Arc\SysConfig;
use Illuminate\Http\Request;

/**
 * phpMyAdmin and phpPgAdmin links of the database pages
 * (templates/pages/list_db.php): served by the web server of the panel's host
 * name under /phpmyadmin/, /phppgadmin/ or the DB_PMA_ALIAS / DB_PGA_ALIAS
 * aliases; they do not work when the panel is reached by IP address.
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
        return filter_var($this->request->getHost(), FILTER_VALIDATE_IP) !== false;
    }

    /** "//host/alias/" of a database type. */
    public function base(string $type): string
    {
        $alias = $type === 'pgsql'
            ? ($this->config->get('DB_PGA_ALIAS') ?: 'phppgadmin')
            : ($this->config->get('DB_PMA_ALIAS') ?: 'phpmyadmin');

        return '//'.$this->request->getHost().'/'.trim($alias, '/').'/';
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
            $buttons[] = ['label' => 'phpMyAdmin', 'url' => $this->base('mysql'), 'disabled' => $this->ipUsed()];
        }
        if (in_array('pgsql', $types, true)) {
            $buttons[] = ['label' => 'phpPgAdmin', 'url' => $this->base('pgsql'), 'disabled' => $this->ipUsed()];
        }

        return $buttons;
    }

    /** Single sign-on into phpMyAdmin needs PHPMYADMIN_KEY (v-add-sys-pma-sso) and a host name. */
    public function ssoAvailable(): bool
    {
        return $this->config->get('PHPMYADMIN_KEY') !== '' && ! $this->ipUsed();
    }

    /**
     * The arcpanel-sso.php link of templates/pages/list_db.php: a token over the
     * database, its owner, the client IP and the time, valid for a minute.
     */
    public function ssoUrl(DatabaseRecord $record, string $clientIp): string
    {
        $time = time();
        $token = password_hash($record->database.$record->user.$clientIp.$time.$this->config->get('PHPMYADMIN_KEY'), PASSWORD_DEFAULT);

        return 'https:'.$this->base('mysql').'arcpanel-sso.php?'.http_build_query([
            'host' => $record->host,
            'database' => $record->database,
            'user' => $record->user,
            'exp' => $time,
            'arcpanel_token' => $token,
        ]);
    }
}
