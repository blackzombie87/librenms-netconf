<?php

/*
 * Default plugin settings. Values saved on the plugin settings page
 * (plugins table, settings JSON) override these. Secrets stored there
 * carry the "crypt:" prefix and are encrypted with the LibreNMS APP_KEY.
 */
return [
    'username' => '',
    'password' => '',
    'key_file' => '',
    'key_passphrase' => '',
    'auth_order' => 'key,password',
    'port' => 22,
    'transport' => 'cli',
    'connect_timeout' => 10,
    'command_timeout' => 30,
    'poll_budget' => 20,
    'definitions_dir' => '',
    'enable_by_default' => false,
    'backoff_max' => 32,
];
