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
    'transport' => 'auto',    // auto = NETCONF subsystem when the server offers it on the SSH port, exec channels otherwise
    'connect_timeout' => 10,
    'command_timeout' => 30,
    'known_hosts' => '',       // OpenSSH known_hosts file; empty = server host keys are not verified
    'poll_budget' => 20,
    'definitions_dir' => '',
    'enable_by_default' => false,
    'backoff_max' => 32,
    'evpn_fabric' => false,    // EVPN fabric view: run definitions with tables: mappings and the fabric resolver
    'evpn_links' => true,      // with the fabric view: ESI-LAG peers as core `links` rows (protocol evpn-esi), Neighbours tab + map
    'evpn_mac' => true,        // with the fabric view: collect the EVPN MAC database per device (`show evpn database`, every third poll); the device attribute netconf_evpn_mac overrides it
    'evpn_mac_moves' => 5,     // fabric check "MAC mobility": active source changes per hour that raise an issue (0 = off)
    'queues' => false,         // collect per-port, per-queue counters (8 queues x 3 counters per port); the device attribute netconf_queues overrides it
];
