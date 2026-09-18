# librenms-netconf

LibreNMS plugin that collects operational data from Junos devices over SSH — either as
`show … | display xml` on a plain SSH session or through the NETCONF subsystem — and maps
the values onto native LibreNMS objects (sensors, per-port metrics, custom metrics) using
YAML definitions. Built for things SNMP cannot deliver on Junos, first of all
EVPN-VXLAN state (duplicate MACs, ESI status, MAC/route counts).

**Status: Phase 1 — connectivity and tooling.** Transports, credentials, the settings
page and the `lnms netconf:test` / `lnms netconf:run` commands are in place. The
definition engine and the poller/discovery module follow in the next phases
(see `NETCONF_PLUGIN_PLAN.md` in the development notes).

## Requirements

- LibreNMS 24.x or newer (plugin packages, `lnms plugin:add`), PHP 8.2+
- Network access from the poller to the devices on port 22 (or 830 for NETCONF)
- A read-only login class on the devices (below)

## Installation

```bash
cd /opt/librenms
./lnms plugin:add saffer-it/librenms-netconf
```

The plugin is enabled automatically. Open *Overview → Plugins → Plugin Admin → netconf* to
set the global defaults (transport, port, username, password or SSH key).

## Device login class (Junos)

The plugin only runs `show` commands. A dedicated read-only class keeps the monitoring
account from doing anything else:

```
set system login class LIBRENMS_NETCONF idle-timeout 5
set system login class LIBRENMS_NETCONF permissions view
set system login class LIBRENMS_NETCONF permissions view-configuration
set system login class LIBRENMS_NETCONF permissions access
set system login class LIBRENMS_NETCONF permissions firewall
set system login class LIBRENMS_NETCONF permissions interface
set system login class LIBRENMS_NETCONF permissions network
set system login class LIBRENMS_NETCONF permissions routing
set system login class LIBRENMS_NETCONF permissions security
set system login class LIBRENMS_NETCONF permissions snmp
set system login class LIBRENMS_NETCONF permissions storage
set system login class LIBRENMS_NETCONF permissions system
set system login class LIBRENMS_NETCONF permissions trace
set system login class LIBRENMS_NETCONF allow-commands "^show "
set system login class LIBRENMS_NETCONF deny-configuration-regexps .*
set system login user librenms class LIBRENMS_NETCONF
set system login user librenms authentication ssh-ed25519 "ssh-ed25519 AAAA… librenms@poller"
```

For the optional `netconf` transport additionally enable the subsystem and allow the
XML-session commands:

```
set system services netconf ssh
set system login class LIBRENMS_NETCONF allow-commands "^(show |netconf|xml-mode|need-trailer)"
```

## Transports

| Mode | How | Default port |
|---|---|---|
| `cli` (default) | one SSH connection, each command runs as `show … \| display xml` on its own exec channel — the approach of [junos_exporter](https://github.com/czerwonk/junos_exporter) | 22 |
| `netconf` | NETCONF subsystem, hello/capability exchange, RFC 6242 end-of-message or chunked framing, `<command format="xml">` for CLI strings and raw `<rpc>` bodies | 830 |

Both return the same XML body. Host keys are **not** verified.

## Credentials

Global defaults live on the plugin settings page. Per device they can be overridden with
device attributes (`netconf_username`, `netconf_password`, `netconf_keyfile`,
`netconf_key_passphrase`, `netconf_port`, `netconf_transport`); a UI for that follows in
Phase 4. Secrets are encrypted with the LibreNMS `APP_KEY` and stored with a `crypt:`
prefix; the settings form never shows them again.

## Commands

```bash
# login + version + effective permissions of the login class
./lnms netconf:test leaf1.example.net
./lnms netconf:test leaf1.example.net --transport=netconf --port=830 --capabilities
./lnms netconf:test 10.0.0.5 --username=librenms --ask-password   # host not yet in LibreNMS

# ad-hoc command, pretty-printed XML (handy while writing definitions)
./lnms netconf:run leaf1 show evpn instance extensive
./lnms netconf:run leaf1 show interfaces extensive et-0/0/0 --output=/tmp/if.xml
./lnms netconf:run leaf1 --transport=netconf --rpc '<get-software-information/>'
```

`device` accepts a hostname, IP, sysName or `device_id`; unknown hosts are tried with the
global settings.

## Development

```bash
composer install
composer check          # php-cs-fixer, phpstan, pest
```

Unit tests need no LibreNMS installation: the transports are exercised against a scripted
NETCONF server (`tests/Support/NetconfServerScript.php`) and recorded, anonymised Junos
replies in `tests/fixtures/junos/`.

To try the plugin in a local LibreNMS checkout add a path repository to its
`composer.json` and run `./lnms plugin:add saffer-it/librenms-netconf`:

```json
{ "type": "path", "url": "/path/to/librenms-netconf", "options": { "symlink": true } }
```

## License

MIT
