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

    // Directory of the v-* commands. sudo must allow hestiaweb to run them
    // (install/common/sudo/arcpanel).
    'bin_path' => env('ARC_BIN_PATH', '/usr/local/arcpanel/bin'),

    'sudo' => env('ARC_SUDO', '/usr/bin/sudo'),

    // Real installation directory, passed to commands as $HESTIA.
    'install_dir' => env('ARC_INSTALL_DIR', '/usr/local/hestia'),

    // Legacy PHP session files shared with web/ and the web terminal.
    'sessions_path' => env('ARC_SESSIONS_PATH', '/usr/local/hestia/data/sessions'),

    // v-* commands only read secret files below /tmp/ (func/main.sh,
    // is_password_valid and is_hash_valid).
    'secret_dir' => env('ARC_SECRET_DIR', '/tmp'),

    // Default timeout of one v-* call, in seconds.
    'command_timeout' => (int) env('ARC_COMMAND_TIMEOUT', 60),

    // How long the output of v-list-sys-config is cached, in seconds.
    'sys_config_ttl' => (int) env('ARC_SYS_CONFIG_TTL', 60),

];
