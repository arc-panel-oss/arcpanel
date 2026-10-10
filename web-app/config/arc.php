<?php

/*
|--------------------------------------------------------------------------
| ArcPanel system paths
|--------------------------------------------------------------------------
|
| Every path the panel app uses on the server is read from here, so a later
| rename of the internal directories only changes this file.
|
*/

return [

    // ArcPanel root as administrators see it (symlink to the real directory).
    'root' => env('ARC_ROOT', '/usr/local/arcpanel'),

    // Directory of the v-* commands. sudo must allow arcpanelweb to run them
    // (install/common/sudo/arcpanel).
    'bin_path' => env('ARC_BIN_PATH', '/usr/local/arcpanel/bin'),

    'sudo' => env('ARC_SUDO', '/usr/bin/sudo'),

    // Real installation directory, passed to commands as $ARCPANEL.
    'install_dir' => env('ARC_INSTALL_DIR', '/usr/local/arcpanel'),

    // Legacy PHP session files of the panel.
    'sessions_path' => env('ARC_SESSIONS_PATH', '/usr/local/arcpanel/data/sessions'),

    // Key that signs web terminal tickets (E39 W2 spec D13), written by
    // v-add-sys-web-terminal-key: root:arcpanelweb 0640, outside data/keys,
    // whose files API v1 takes for API keys.
    'terminal_key' => env('ARC_TERMINAL_KEY', '/usr/local/arcpanel/data/web-terminal/ticket.key'),

    // v-* commands only read secret files below /tmp/ (func/main.sh,
    // is_password_valid and is_hash_valid).
    'secret_dir' => env('ARC_SECRET_DIR', '/tmp'),

    // The folder that the internal /backup/ location of arcpanel-nginx
    // serves (root /, so /backup). Archive downloads (E7 page, API v2 D35)
    // need the BACKUP folder of arcpanel.conf to be this folder; set it only
    // when nginx is changed to serve another one.
    'backup_dir' => env('ARC_BACKUP_DIR', '/backup'),

    // Default timeout of one v-* call, in seconds.
    'command_timeout' => (int) env('ARC_COMMAND_TIMEOUT', 60),

    // How long the output of v-list-sys-config is cached, in seconds.
    'sys_config_ttl' => (int) env('ARC_SYS_CONFIG_TTL', 60),

    // File manager staging tree (E3), created by v-add-sys-panel-files. The
    // commands accept staged files only below <root>/uploads of the
    // installed panel app.
    'files_staging' => env('ARC_FILES_STAGING', storage_path('app/files')),

];
