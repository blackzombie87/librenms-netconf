# librenms-netconf

LibreNMS plugin that collects operational data from Junos devices over SSH — either as
`show … | display xml` on a plain SSH session or through the NETCONF subsystem — and maps
the values onto native LibreNMS objects (sensors, per-port metrics, custom metrics) using
YAML definitions. Built for things SNMP cannot deliver on Junos, first of all
EVPN-VXLAN state (duplicate MACs, ESI status, MAC/route counts).

**Status: Phase 5 — feature complete for v1.0.** Transports, credentials and the settings
page, the YAML definition engine and the `netconf` poller/discovery module are verified
against an EX4650 (Junos 23.4R2). Extracted values are stored as native LibreNMS sensors
(health tab, graphs, alert rules), per-port metrics and custom metrics with their own RRDs
and graphs. The web UI has a NETCONF status page, a per-device page (credentials, test
connection, discover/poll now), a device overview panel, metric tables with graphs, a port
tab with the per-port counters and a "run a show command" form. Remaining before a release:
packaging on Packagist. The
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

Then run the migrations and enable devices:

```bash
./lnms migrate
./lnms netconf:device leaf1 --enable            # uses the global credentials
./lnms netconf:device leaf1 --enable --set-username=librenms --set-key=/opt/librenms/.ssh/netconf_ed25519
./lnms device:discover leaf1 -m netconf         # creates the sensors
./lnms device:poll leaf1 -m netconf             # records values (the regular poller does this every cycle)
```

Instead of enabling devices one by one, set *Enable for all devices* on the settings page;
`--disable` then opts a device out. The module also appears in the device's *Modules* tab.

## Web UI

- **NETCONF** in the Plugins menu (`/plugin/netconf/status`): every enabled or previously
  polled device with transport, matched definitions, last success, poll count, failures and
  back-off; admins get a *Run a show command* form that prints the XML reply as the plugin
  sees it. `/plugin/netconf/definitions` lists the loaded definitions and any YAML errors.
- **Device page** (`/plugin/netconf/device/<id>`, linked from the overview panel): status,
  effective credentials, per-device overrides (polling on/off, transport, port, user,
  password, key file, passphrase), *Test connection*, *Discover now* and *Poll now*.
  Secrets are encrypted before they are stored and never displayed.
- **Device overview panel**: polling state, matched definitions, sensor summary with the
  critical ones linked, and the first rows of every custom metric mapping; the full tables
  are at `/plugin/netconf/device/<id>/metrics`.
- **Metric tables** (`/plugin/netconf/device/<id>/metrics`): every custom metric row with
  its values and labels, a graph per row, and per mapping a fold-out with one graph per
  field (one line per row); the same for port metrics. Period selector 6h/day/week/month/
  year.
- **Port tab** (*Plugins* tab of a port): the per-port counters with a combined rate graph
  of all counters, gauges individually, and a fold-out with one graph per counter.

Graphs are rendered from the plugin's RRDs by `/plugin/netconf/graph/...` using the same
size, font and colour parameters as core graphs (SVG or PNG per the *webui.graph_type*
setting); counters are shown as rates per second.

Viewing needs access to the device (global-read for the status page); changing anything or
talking to a device needs the admin role.

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

Both return the same XML body.

### Host keys

By default the server host key is **not** verified (any key is accepted, as phpseclib
does). For production set *Host key check (known_hosts)* on the settings page to an
OpenSSH `known_hosts` file readable by the poller user, for example
`/opt/librenms/.ssh/known_hosts`. Every device must then have an entry, otherwise the
connection is refused with the fingerprint and the line to add:

```bash
ssh-keyscan -p 22 leaf1.example.net >> /opt/librenms/.ssh/known_hosts   # compare the fingerprint on the console first
./lnms netconf:test leaf1.example.net                                    # prints "host_key: ssh-ed25519 SHA256:… (verified against …)"
./lnms netconf:test leaf1.example.net --known-hosts=-                    # one-off without verification
```

Hashed entries, `[host]:port` entries for non-default ports, wildcards and `@revoked`
markers are understood; a key that differs from every stored key for the host is reported
as a host key mismatch and the device goes into back-off like any other connection failure.

## Credentials

Global defaults live on the plugin settings page. Per device they can be overridden with
device attributes (`netconf_username`, `netconf_password`, `netconf_keyfile`,
`netconf_key_passphrase`, `netconf_port`, `netconf_transport`); a UI for that follows in
Phase 4. Secrets are encrypted with the LibreNMS `APP_KEY` and stored with a `crypt:`
prefix; the settings form never shows them again.

## Commands

```bash
# login, "show version" and the login class' permission list ("show cli authorization");
# warns when "view" is missing or the account has configuration rights
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

## Definitions

Definitions are YAML files in `resources/definitions/<vendor>/`. A user directory
(setting *User definitions directory*, default `storage/app/netconf-definitions/`) is
loaded afterwards and overrides shipped files with the same `name`.

Shipped (Junos):

| Definition | Commands | Produces |
|---|---|---|
| `junos-evpn` | `show evpn instance extensive`, `show evpn database state duplicate`, `show evpn l3-context` | count sensors for duplicate MACs (per instance + total, `limit: 0`), local ESIs without remote PE, degraded bridge domains, L3 contexts; state sensors for ESI resolution, ESI-LAG status, EVPN interface and IRB status; metrics per instance (local/remote MACs, neighbours, ESIs), per neighbour (route counts by type) and per ESI-LAG (DF, remote PEs) |
| `junos-evpn-esi` | `show mac-vrf forwarding vxlan-tunnel-end-point esi` | metrics per ESI-LAG (remote VTEPs, remote MACs) and per ESI-LAG/remote VTEP pair |
| `junos-routing` | `show route summary`, `show bgp summary` | count sensors for active and hidden routes per table, BGP peers configured/down (`limit: 0`); metrics per table, per table/protocol, per BGP RIB, per peer (flaps, messages, uptime, state) and per peer/RIB |
| `junos-l2` | `show ethernet-switching table summary` | MAC table size and static entries (L2NG and pre-ELS shapes) |
| `junos-interfaces` | `show interfaces extensive` | per-port counters matched by `snmp-index` = ifIndex: CRC in/out, oversized, jabber, fragments, code violations, pause frames, framing errors, runts, MTU errors, carrier transitions, FEC corrected/uncorrected words and rates, PCS errored seconds, BPDU-block state |
| `junos-interface-queues` | `show interfaces extensive` | per port/queue queued, transmitted and dropped packets; opt-in per device via attribute `netconf_queues=1` |
| `junos-srx-cluster` | `show chassis cluster status` | state sensors per redundancy group and node (primary/secondary/…), monitor failures, failover counters; only on hardware matching `/srx/i` |
| `junos-alarms` | `show system alarms`, `show chassis alarms` | major/minor alarm counts (`limit: 0` for major) |
| `junos-ntp` | `show ntp status`, `show ntp associations` | state sensor synchronised/unsynchronised, stratum (`limit: 15`), reachable peers (`limit_low: 1`); metrics for offset, root delay/dispersion, jitter, frequency and per peer |
| `junos-system` | `show system uptime`, `show system commit`, `show krt queue` | KRT queue length (`warn_limit: 100`); metrics for uptime, seconds since last commit, last commit epoch/user/client, load averages, per KRT queue |
| `junos-license` | `show system license usage` | features in use without a valid license (`warn_limit: 0`); metrics per feature |
| `junos-lacp` | `show lacp interfaces` | state sensor per LAG member (mux state), count of members not distributing per LAG (`limit: 0`) |
| `junos-ldp` | `show ldp neighbor`, `show ldp session` | neighbour count, sessions not operational (`limit: 0`), state sensor per session |
| `junos-rpki` | `show validation session`, `show validation statistics` | state sensor per cache session, sessions not up (`limit: 0`), invalid origin count; metrics per session (flaps, prefixes) and the validation statistics |
| `junos-vrrp` | `show vrrp summary` | state sensor per interface/group (master, backup, init), groups neither master nor backup (`limit: 0`) |

Commands whose subsystem is not running ("LDP instance is not running", "vrrp subsystem
not running") are recognised and skipped without creating sensors, so every definition can
be shipped enabled. The LDP, RPKI and VRRP element paths come from junos_exporter's
collectors and were not yet verified on a device running those protocols; the other
definitions are verified on an EX4650.

Alert-rule examples beyond the sensor limits, using the JSON columns of `netconf_metrics`:

```sql
-- configuration committed in the last 10 minutes (junos-system/uptime)
SELECT * FROM netconf_metrics WHERE netconf_metrics.device_id = ? AND mapping = 'uptime'
  AND JSON_EXTRACT(`values`, '$.config_age') < 600
-- BGP peer not established (junos-routing/bgp-peer)
SELECT * FROM netconf_metrics WHERE netconf_metrics.device_id = ? AND mapping = 'bgp-peer'
  AND JSON_UNQUOTE(JSON_EXTRACT(labels, '$.state')) != 'Established'
```

### Schema

```yaml
name: junos-example              # lowercase, used in sensor_type / RRD names
description: What it collects
enabled: true                    # optional
match:                           # all rules must match; literal, "/regex/" or a list
  os: junos
  hardware: '/^(EX46|QFX5)/'
  version: '/^2[3-9]\./'
  hostname: '/leaf/'
  attrib: netconf_example        # device attribute must be truthy (opt-in)

commands:                        # each runs once per poll, shared by all mappings
  key:
    cli: show something          # only "show ..." is allowed
    optional: true               # device error -> mappings skipped silently
    every: 3                     # only every 3rd poll
  other: show something else     # shorthand
  raw: { rpc: '<get-something/>' }   # netconf transport only

sensors:
  - id: my-count                 # optional, default sensorN; part of sensor_type
    class: count                 # any LibreNMS sensor class (count, state, percent, …)
    command: key
    rows: //table-row            # one sensor per node; omit for a single sensor
    when: not(skip)              # XPath boolean filter per row (optional)
    repeat: count(node/name)     # flattened tables: run the row N times with {n} = 1..N
    index: string(name)          # XPath (relative to the row) or template; must be unique
    descr: 'Thing {index} ({row:type})'   # template: {index} {re} {n} {row:<xpath>} {device:hostname}
    value: number(active)        # XPath; NaN/empty -> row skipped
    value_any: [number(new-shape), number(old-shape)]   # first non-empty wins
    group: Routing
    limit: 0                     # limit, limit_low, warn_limit, warn_limit_low
    divisor: 1
    multiplier: 1
  - id: my-state
    class: state
    command: key
    rows: //item
    index: string(name)
    descr: 'Item {index}'
    value: string(status)
    states:                      # label -> value/generic (0 ok, 1 warn, 2 crit, 3 unknown)
      Up:      { value: 1, generic: 0 }              # exact match on the label
      Down:    { match: '/^Down/', value: 2, generic: 2 }
      Unknown: { match: '/.*/', value: 3, generic: 3, default: true }

ports:
  - id: ethernet
    command: key
    rows: //physical-interface[snmp-index]
    match: { port_field: ifIndex, xpath: string(snmp-index) }   # or ifName / ifDescr / ifAlias
    metrics:
      crc_in: { xpath: number(ethernet-mac-statistics/input-crc-errors), type: COUNTER }
      bpdu:   "number(bpdu-error != 'none')"        # GAUGE by default

metrics:
  - id: table
    command: key
    rows: //route-table
    index: string(table-name)
    descr: 'Route table {index}'
    fields:                      # GAUGE/COUNTER/DERIVE -> RRD data source (name max 19 chars)
      total:  number(total-route-count)
      flaps:  { xpath: number(flap-count), type: COUNTER }
      state:  { xpath: string(peer-state), type: string }   # label only, never an RRD data source
```

Namespaces are stripped before evaluation, so paths never need prefixes; attributes keep
their local name (`elapsed-time/@seconds`). Prefer `string(...)` over `number(...)` for
values that may carry a sign or a unit (`+0.325`, `1,234 bps`): XPath's `number()` turns
those into NaN, while the plugin parses strings itself. Rows inside `multi-routing-engine-results`
(Virtual Chassis) are matched by `//` expressions like any other and expose `{re}`.

```bash
./lnms netconf:validate                                  # lint shipped + user definitions
./lnms netconf:validate my.yaml --replay=tests/fixtures/junos   # extract from recorded replies
./lnms netconf:preview leaf1                             # live dry run, nothing is stored
./lnms netconf:preview leaf1 --only=junos-evpn --save=/tmp/leaf1   # and record fixtures
```

## What lands where

| Definition section | LibreNMS object | Storage | Alerting |
|---|---|---|---|
| `sensors` | native sensors with `poller_type = netconf`, `sensor_type = netconf-<definition>-<id>`; state sensors get state translations | `sensors` table, `rrd/<host>/sensor-<class>-netconf-…rrd`, any other configured datastore | standard sensor alert rules (`sensors.sensor_current > sensors.sensor_limit`, state generic value); eventlog on threshold crossing and state change |
| `ports` | rows matched to the `ports` table by ifIndex (or ifName/ifDescr/ifAlias) | `netconf_port_metrics` (last values as JSON), `rrd/<host>/netconf-port-<port_id>-<definition>-<mapping>.rrd`, one data source per field | Advanced-SQL alert rules on `netconf_port_metrics.values` |
| `metrics` | free-form rows | `netconf_metrics` (last values + labels as JSON), `rrd/<host>/netconf-<definition>-<mapping>-<index>.rrd` | Advanced-SQL alert rules on `netconf_metrics.values` |

Per device, `netconf_device_status` keeps the transport, matched definitions, poll count,
last success, last error and the back-off: after a failed session the device is skipped
for 1, 2, 4, … polls (capped by *Back-off maximum*), with one eventlog entry on the first
failure and one on recovery. `lnms netconf:device <device>` shows this row.

Notes:

- Sensors whose command failed or was skipped in a run are kept, not deleted; sensors
  disappear only when their command succeeded and the row is gone.
- User-customised limits (sensor "custom" flag in the UI) survive rediscovery.
- The RRD of a metric or port row has one data source per GAUGE/COUNTER/DERIVE field of
  the mapping, in YAML order, created on the first write; values missing in a reply are
  written as unknown. Mark text fields `type: string` so they do not become empty data
  sources. If a definition later gains or reorders numeric fields, delete the RRD to
  recreate it (`rrdtool info` shows the current data sources).
- The core `sensors` poller lists netconf sensors as "Checking (netconf) …" and skips
  them; their `sensor_oid` is sysUpTime so that check stays cheap.

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
