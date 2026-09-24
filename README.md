# librenms-netconf

[![CI](https://github.com/blackzombie87/librenms-netconf/actions/workflows/ci.yml/badge.svg)](https://github.com/blackzombie87/librenms-netconf/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/saffer-it/librenms-netconf)](https://packagist.org/packages/saffer-it/librenms-netconf)

LibreNMS plugin that collects operational data from Junos devices over SSH — either as
`show … | display xml` on a plain SSH session or through the NETCONF subsystem — and maps
the values onto native LibreNMS objects (sensors, per-port metrics, custom metrics) using
YAML definitions. Built for things SNMP cannot deliver on Junos, first of all
EVPN-VXLAN state (duplicate MACs, ESI status, MAC/route counts).

**Status: 1.2.1 on Packagist** (`lnms plugin:add saffer-it/librenms-netconf`). Transports,
credentials and the settings page, the YAML definition engine and the `netconf`
poller/discovery module are verified against an EX4650 (Junos 23.4R2); the LDP, RPKI and
VRRP definitions against recorded replies of a Junos 22.2
MPLS router. Extracted values are stored as native LibreNMS
sensors (health tab, graphs, alert rules), per-port metrics and custom metrics with their
own RRDs and graphs. The web UI has a NETCONF status page, a **NETCONF tab on the device
page** (status, metrics, ESI-LAGs and, for admins, credentials with test connection and
discover/poll now), a device overview panel, the EVPN fabric pages with topology map and
MAC search, a port tab with the per-port counters and a "run a show command" form. Not in
this version: license expiry (see `CHANGELOG.md`).

## Requirements

- LibreNMS 24.x or newer (plugin packages, `lnms plugin:add`), PHP 8.2+
- Network access from the poller to the devices on port 22 (or 830 for NETCONF)
- A read-only login class on the devices (below)

## Installation

```bash
cd /opt/librenms
./lnms plugin:add saffer-it/librenms-netconf
./lnms route:cache
./lnms view:clear
```

`route:cache` is not optional on a production install: LibreNMS caches its routes in
`composer install` (`artisan optimize`), `plugin:add` does not refresh that cache, and until it
is rebuilt the plugin's pages and menu entry are missing. `lnms plugin:enable netconf` rebuilds
it too, and `daily.sh` clears it on the next update. Since 1.2.1 the plugin notices the state,
hides its own UI and writes a notification instead of breaking the page; older versions took the
whole web UI down, because the plugin menu entry is rendered from LibreNMS's own menu on every
page. `artisan cache:clear` does *not* help here — that is the application cache.

`view:clear` matters for the same reason on every install and upgrade: Composer extracts the
package with the archive's timestamps, which can be *older* than compiled Blade views LibreNMS
cached earlier, and Laravel then keeps the compiled copy (it recompiles only when the source is
newer). The symptom is pages from the previous version — new code, old screens.

The plugin is enabled automatically. Open *Overview → Plugins → Plugin Admin → netconf* to
set the global defaults (transport, port, username, password or SSH key). The *poll budget*
there is a scheduling budget: the collector checks it between commands and stops issuing new
ones once it is spent, so a run takes the budget plus the command in flight, extraction and
storage; it does not abort a command.

Then run the migrations:

```bash
./lnms migrate
```

### Enabling your first device

In the web UI: open the device, go to its **NETCONF** tab and press *Enable NETCONF for this
device*, then *Test connection* and *Discover now*. The tab is offered on every device a shipped
definition matches (Junos today) as soon as you are an admin, so this is the path for a single
device. Credentials for that one device (if it differs from the global settings) are on the tab's
*Edit* section.

For a fleet, the *NETCONF* status page (Plugins menu) enables every device of a device group
and/or os at once, and *Enable for all devices* on the settings page turns it on globally.

The same from the command line:

```bash
./lnms netconf:device leaf1 --enable            # uses the global credentials
./lnms netconf:device leaf1 --enable --set-username=librenms --set-key=/opt/librenms/.ssh/netconf_ed25519
./lnms netconf:device --os=junos --enable       # or --group=<name>
./lnms device:discover leaf1 -m netconf         # creates the sensors
./lnms device:poll leaf1 -m netconf             # records values (the regular poller does this every cycle)
```

`--disable` opts a device out again. The module also appears in the device's *Modules* tab.

## Web UI

- **NETCONF** in the Plugins menu (`/plugin/netconf/status`): every enabled or previously
  polled device with transport, matched definitions, last success, poll count, failures and
  back-off; admins get an *Enable per device group or os* form (the bulk enable of
  `lnms netconf:device --group/--os`) and a *Run a show command* form that prints the XML
  reply as the plugin sees it. `/plugin/netconf/definitions` lists the loaded definitions and any YAML errors.
- **Device page** (`/plugin/netconf/device/<id>`, linked from the overview panel): status,
  effective credentials, per-device overrides (polling on/off, transport, port, user,
  password, key file, passphrase), *Test connection*, *Discover now* and *Poll now*.
  Secrets are encrypted before they are stored and never displayed.
- **Device overview panel**: polling state, matched definitions, sensor summary with the
  critical ones linked, and the first rows of every custom metric mapping; the full tables
  are at `/plugin/netconf/device/<id>/metrics`. With the EVPN fabric view enabled the panel
  names the device's fabric, role and VTEP with links into the fabric tabs, and a second
  panel **EVPN multihoming** lists the device's ESI-LAGs with peer device, peer LAG, mode,
  DF/BDF and LAG state (all rows on the device page).
- **Metric tables** (`/plugin/netconf/device/<id>/metrics`): one fold-out per mapping
  (collapsed, with row and field counts) holding every custom metric row with its values and
  labels and a graph link per row, plus a nested fold-out with one graph per field (one line
  per row); the same for port metrics. Graph images are created only when their fold-out is
  opened, so the page itself costs no rrdtool runs. Period selector 6h/day/week/month/year,
  expand/collapse all.
- **Port tab** (*Plugins* tab of a port): the per-port counters with a combined rate graph
  of all counters, gauges individually, and a fold-out with one graph per counter.

Graphs are rendered from the plugin's RRDs by `/plugin/netconf/graph/...` using the same
size, font and colour parameters as core graphs (SVG or PNG per the *webui.graph_type*
setting); counters are shown as rates per second.

Viewing needs access to the device (global-read for the status page); changing anything or
talking to a device needs the admin role.

## Device login class (Junos)

The plugin only runs `show` commands. A dedicated read-only class keeps the monitoring
account from doing anything else. Verified 2026-09-21 on an EX4650 (Junos 23.4R2-S7.4):
the single permission `view` is enough for every shipped definition (22 commands over cli/22
and netconf/830, password and SSH-key login), and `show cli authorization` parses for it.

```
set system login class LIBRENMS_NETCONF idle-timeout 5
set system login class LIBRENMS_NETCONF permissions view
set system login class LIBRENMS_NETCONF deny-commands "^(file|request|restart|start|clear|test|monitor|op|load|save|copy|set|edit|configure)"
set system login user librenms class LIBRENMS_NETCONF
set system login user librenms authentication ssh-ed25519 "ssh-ed25519 AAAA… librenms@poller"
```

Notes from the verification:

- `view` alone also permits `file list` (and `ping`, `traceroute`, `monitor` are governed
  by other flags), so the `deny-commands` line is what actually pins the account to `show`.
  `allow-commands "^show "` is not needed: on Junos `allow-commands` *adds* commands on top
  of the permission flags, it never restricts them.
- `view-configuration` is not needed by any shipped definition; leave it out unless a user
  definition reads `show configuration`. Without the `configure` permission
  `deny-configuration-regexps` has nothing to deny.
- Do not use the wider set `access firewall interface network routing security snmp
  storage system trace`; it changes nothing for the plugin and grants operational commands
  the plugin never issues.

For the NETCONF subsystem (taken by the default `auto` transport when present, required by
`netconf`) enable the service; it also speeds polling up considerably, see *Transports*:

```
set system services netconf ssh
```

The subsystem (on port 830 and on the SSH port) is authorised with the same permission flags as the CLI; no
`allow-commands` entry for `xml-mode` or `netconf` is needed (verified on 23.4R2, the class
above logs in and answers `show version` and `<command>` RPCs). `set system services netconf
rfc-compliant` changes reply formatting but the server still advertises only `base:1.0`
(end-of-message framing) on 23.4R2; chunked framing (`base:1.1`) is implemented and
unit-tested, but has not been seen live.

## Transports

| Mode | How | Default port |
|---|---|---|
| `auto` (default) | one SSH login, then the `netconf` subsystem is requested on that connection; when the server refuses it (no `set system services netconf ssh`) the same connection continues with exec channels as `cli`. No second login or timeout; the status page shows which mode was negotiated | 22 |
| `cli` | one SSH connection, each command runs as `show … \| display xml` on its own exec channel — the approach of [junos_exporter](https://github.com/czerwonk/junos_exporter) | 22 |
| `netconf` | NETCONF subsystem, hello/capability exchange, RFC 6242 end-of-message or chunked framing, `<command format="xml">` for CLI strings and raw `<rpc>` bodies | 830 |

All three return the same XML body. Junos offers the NETCONF subsystem on the SSH port as
soon as `netconf ssh` is configured, so `auto` needs nothing but port 22. Prefer the
subsystem where you can: a full preview of the shipped definitions on an EX4650 took 13 s
over NETCONF and 45 s over exec with the recommended `deny-commands` class (19 s without it),
because every exec channel starts a fresh CLI and pays the login-class evaluation again.
`netconf:test` prints `transport: netconf (auto)` or `cli (auto)` plus the refusal reason.
Pick `cli` explicitly for a device whose NETCONF service must stay off, `netconf` to use
port 830 (a separate `netconf` service ACL, for example).

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

### Distributed pollers

The module runs inside the poller worker, so with `distributed_poller` every node that may
poll a NETCONF device needs the node-local files the settings point at — the settings
themselves live in the database and are shared, the paths in them are not:

| Setting | Must exist on every poller node |
|---|---|
| *Private key file* (`key_file`, or the per-device `netconf_keyfile` attribute) | the key itself, same path, readable by the poller user |
| *Host key check* (`known_hosts`) | the `known_hosts` file with an entry for every device — a node without it refuses the connection as a host key mismatch |
| *Definitions directory* (`definitions_dir`) | the user definitions; a node that cannot see them polls with the shipped set only, silently |

Two more shared-state points:

- **`APP_KEY`.** Passwords and key passphrases are stored with a `crypt:` prefix and
  decrypted on the polling node. LibreNMS already requires the same `APP_KEY` on all nodes;
  a node with a different one raises a `DecryptException` while building the credentials —
  before any connection — and the module aborts for that device.
- **Cache store.** The EVPN fabric resolver (`evpn_fabric`) runs after every leaf poll and
  serialises itself on the `netconf:fabric-resolver` cache lock. That lock spans the
  installation only if the cache store does (Redis, Memcached, database); with the file store
  every node holds its own lock and two nodes can resolve at the same time. Each resolve is a
  full recompute that persists inside one transaction, so the result stays consistent — the
  nodes just repeat each other's work.

RRD needs nothing special: sensors and metrics go through LibreNMS's `Datastore`, so
`rrdcached` applies exactly as it does for core modules. `netconf:uninstall --purge` is the
exception — it deletes only RRD files it can see locally and lists the rest for removal on
the rrdcached host.

## Credentials

Global defaults live on the plugin settings page. Per device they can be overridden on the
device page (`/plugin/netconf/device/<id>`) or with `lnms netconf:device`; the overrides are
device attributes (`netconf_username`, `netconf_password`, `netconf_keyfile`,
`netconf_key_passphrase`, `netconf_port`, `netconf_transport`). Secrets are encrypted with
the LibreNMS `APP_KEY` and stored with a `crypt:` prefix; neither form shows them again.

The plugin only needs `show` commands. Run the monitoring account in the read-only login
class above rather than `super-user`: `lnms netconf:test` warns when the account has
configuration rights.

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

```bash
# polling on/off and credential overrides per device (stored as device attributes)
./lnms netconf:device leaf1 --enable --set-transport=netconf --set-port=830
# the same change for every device of a device group and/or os (G7)
./lnms netconf:device --group="Leaf switches" --enable
./lnms netconf:device --os=junos --group=12 --set-username=librenms --set-ask-password
./lnms netconf:device --os=junos                     # list only: enabled state and matching definitions
```

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
| `junos-evpn-fabric` | `show evpn instance extensive`, `show mac-vrf forwarding vxlan-tunnel-end-point source` / `remote` / `esi` / `remote mac-table` (every 3rd poll), `show interfaces vtep` | rows for the EVPN fabric tables (`netconf_evpn_neighbor`, `_esi`, `_vni`, `_vni_vtep`, `_tunnel`); only with the *EVPN fabric view* setting |
| `junos-evpn-fabric-mac` | `show evpn database` (every 3rd poll) | `netconf_evpn_mac`: the EVPN MAC database with active source (ESI / remote VTEP / local IFL) and IPs; opt-in per device via attribute `netconf_evpn_mac=1`, and only with the *EVPN fabric view* setting |

Commands whose subsystem is not running ("LDP instance is not running", "vrrp subsystem
not running") are recognised and skipped without creating sensors, so every definition can
be shipped enabled. Only commands every Junos device answers are required in the shipped
definitions (`show system uptime`, `show system alarms`, `show chassis alarms`, `show route
summary`, `show interfaces extensive`); a required command the device does not answer fails
the run like a lost connection: the status row records it, the device backs off and the
first failure lands in the eventlog. The same command in several definitions runs once with
the merged settings — required if any use is required, as often as the most frequent use —
and `lnms netconf:validate` prints a hint when definitions disagree. The LDP, RPKI and VRRP definitions were verified against recorded
replies of a Junos 22.2 MPLS router (anonymised copies in `tests/fixtures/junos/`); the
other definitions live on an EX4650.

Alert rules for the sensors and for the JSON columns of `netconf_metrics` are in
*Alerting* below.

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
    optional: true               # device error -> mappings skipped silently; without it the
                                 # error fails the run (status, back-off, eventlog)
    every: 3                     # only every 3rd poll
    filter: 'xe-0/0/*'           # narrows the command: appended, or placed at {filter} in cli
  other: show something else     # shorthand
  raw: { rpc: '<get-something/>' }   # needs the NETCONF subsystem (netconf, or auto that negotiated it)

sensors:
  - id: my-count                 # optional, default sensorN; part of sensor_type
    class: count                 # any LibreNMS sensor class (count, state, percent, …)
    command: key
    rows: //table-row            # one sensor per node; omit for a single sensor
    when: not(skip)              # XPath boolean filter per row (optional)
    repeat: count(node/name)     # flattened tables: run the row N times with {n} = 1..N
    index: string(name)          # XPath (relative to the row) or template; must be unique and
                                 # at most 128 chars (metrics: 191): a longer one is stored as
                                 # its first 119 chars + "," + 8 hex digits of its sha1, and
                                 # netconf:validate --replay warns about it
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

tables:                        # rows for the plugin's EVPN fabric tables (netconf_evpn_*), no RRD
  - id: vni
    table: vni                 # neighbor, esi, vni, vni_vtep, tunnel or mac; column types are fixed
    command: key
    rows: //vxlan-format[vn-id]
    columns:                   # every key column of the table is required (vni here)
      vni: number(vn-id)
      instance: string(routing-instance-name)
      source_vtep: string(ancestor::svtep-format/source-vtep-address)
      irb_ifname: { xpath: string(irb), transform: duration }   # transforms: duration, evpn_source, timestamp
```

Table columns are coerced to the column type (`int`, `string`, `ip`, `mac` as 12 hex digits,
`bool` from XPath booleans or Up/Down/Yes/No texts, `json` from a node-set or a space separated
text, `datetime`); a value that does not fit is stored as null with a warning. Rows are merged on
the key, so several mappings (and commands) may fill different columns of the same row; rows that
vanish from a reply are deleted per device once every mapping of that table delivered data.

`filter:` keeps big replies small: Junos accepts an interface pattern before or after the
options (`show interfaces extensive xe-0/0/*`, `show interfaces et-0/0/[0-3] extensive`), so a
user definition can copy `junos-interfaces` with `filter: 'et-*'` and the 1 MB reply of a 48-port
leaf shrinks to the uplinks. The filter is part of the command identity: two definitions with
different filters run the command twice. Fixtures for `--replay` are named after the filtered
command.

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
| `tables` | rows of the EVPN fabric tables, typed columns, merged on the key across mappings, pruned per device | `netconf_evpn_<table>` (neighbor, esi, vni, vni_vtep, tunnel, mac); no RRD. The fabric resolver derives `netconf_evpn_{vtep,fabric,fabric_member,underlay_link}` from them, the checks `netconf_evpn_issue{,_device}` and the *EVPN fabric issues* sensor, and, with *ESI peers as neighbours*, rows in the core `links` table (`protocol = evpn-esi`) | Advanced-SQL alert rules on the `netconf_evpn_*` tables (only with the *EVPN fabric view* setting) |

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
  sources. When a definition gains numeric fields the plugin appends the data sources to
  the existing files (`rrdtool tune`, once) and keeps writing in the file's order, so the
  history stays and no value lands in the wrong data source.
- The core `sensors` poller lists netconf sensors as "Checking (netconf) …" and skips
  them; their `sensor_oid` is sysUpTime so that check stays cheap.

## EVPN fabric view (preview)

Enable *EVPN fabric view* on the settings page to collect the per-leaf EVPN tables
(`junos-evpn-fabric`, four extra commands per leaf) and to aggregate them across devices:
every VTEP or EVPN peer address becomes a node, resolved to a LibreNMS device where one owns
the address; EVPN neighbours, ESI peers, VXLAN tunnels, EVPN BGP sessions and confirmed
underlay links (shared point-to-point subnet with a BGP or OSPF session, LLDP as
confirmation) connect the nodes, and every connected component is one fabric
(`netconf_evpn_fabric`, key = lowest VTEP address, members in `netconf_evpn_fabric_member`).
Roles: `leaf` (terminates VXLAN), `gateway` (anycast IRBs), `spine` (EVPN session only),
plus a `border` flag for L3 contexts. VTEPs that are not monitored devices are kept as
"unknown" members. A member row with `pinned = 1` keeps its fabric and role on recompute.

```bash
./lnms netconf:fabric --links            # fabrics, members, underlay links
./lnms netconf:fabric --resolve          # recompute from the current tables
```

The MAC database (`netconf_evpn_mac`, ~5 000 rows and 1.3 MB per busy leaf) is opt-in per
device like the queue counters: set the device attribute `netconf_evpn_mac` to `1`.

### EVPN multihoming peers as neighbours

Every ESI-LAG of a leaf identifies its multihoming peer: the ESI is the same on both leaves
and the remote PE addresses name the peer's VTEP. The fabric resolver turns that into

- the **EVPN multihoming** table on the device overview and the plugin's device page: local
  LAG (linked to the port), peer device (linked when it is a LibreNMS device, otherwise the
  BGP description or the VTEP address), the peer's LAG for the same ESI (when the peer is
  polled by the plugin too), mode, DF/BDF per side, LAG state and the remote MAC count;
  ESIs without any remote PE are flagged;
- rows in the core `links` table with `protocol = evpn-esi` (setting *ESI peers as
  neighbours*, default on), so the peer appears in the device's **Neighbours** tab and on
  the map like an LLDP neighbour. One row per local ESI-LAG and remote PE; the local LAG
  must exist as a port (rows of a LAG core has not discovered yet are skipped). Switching
  the setting off deletes the rows on the next resolve; `netconf:uninstall --purge` deletes
  them too.

Caveat: core's `discovery-protocols` module deletes every `links` row of a device that its
own LLDP/CDP run did not produce. The plugin's discovery module runs after it (last in
`discovery_modules`) and writes the rows again in the same run, so they are only ever
missing for the seconds in between and their `id` changes on every discovery.

### Fabric pages

With the fabric view on, the Plugins menu gains **EVPN fabrics** (`/plugin/netconf/fabrics`):
one row per fabric with members by role, instances, VNIs, ESIs, MACs and health badges. Each
fabric has tabs:

| Tab | Content |
|---|---|
| Overview | counts, health, notes (admins can rename the fabric) and the **topology map**: gateways / spines on top, leaves grouped by site (device location, inherited by ESI partners) or ESI pair; underlay links coloured by BGP/OSPF state, dashed for WAN, grey for LLDP-only, a stub where the far end is not a member yet; EVPN neighbour arcs (red when only one monitored side lists the other) and ESI pair brackets, each with a toggle |
| Members | device, VTEP / router-id, role, platform, location, instances, VNIs, ESI-LAGs (DF count), MACs, neighbours, tunnels, collector state; unknown VTEPs with their BGP description |
| BGP overlay | per member and peer: state and uptime (core `bgpPeers` with the evpn SAFI, or the plugin's `show bgp summary` rows), flaps, `bgp.evpn.0` prefix counts, EVPN route counts by type from `show evpn instance extensive`; a peer that other members have and one lacks is listed as *missing* |
| VNIs | per VNI: VLAN tag per leaf (mismatch flagged), carriers, flood list with gaps and stale entries between monitored carriers, orphans, anycast IRBs, remote MACs; filter and issues-only switch |
| ESI / multihoming | per Ethernet segment: every PE (monitored sides with LAG and resolution state, remote-only PEs), mode, DF/BDF, aliasing, LACP members not distributing, remote MACs; flags for single PE, DF disagreement, mode mismatch, LAG down, unresolved, aliasing off |
| Tunnels | per member: `vtep.N` per remote VTEP with mode, next-hop, and — once core has discovered the IFL as a port — traffic, errors and a graph; reverse-tunnel check where the far end is monitored |
| MACs | the MAC search scoped to the fabric |
| Checks | the open issues of the consistency checks (below) with severity, message, involved devices, first and last seen; filter by severity, check and text; legend of every check |

**MAC search** (`/plugin/netconf/evpn/mac?q=`, also linked from the fabric list): a MAC in any
notation (or a prefix), an IP or a VNI. Results are every opted-in leaf's view from the EVPN
database — local port, ESI with its PEs and their LAGs, or remote VTEP resolved to the device
— next to the core FDB and ARP rows for the same MAC or IP.

Everything on these pages is read from the `netconf_evpn_*` tables and core tables; nothing
talks to a device. Only sessions, flood lists and ESIs of *monitored* members are known, so
gaps can only be judged between monitored leaves.

### Fabric checks

At the end of every resolve (each poll of a member leaf) the plugin runs a set of consistency
checks over the fabric and keeps the findings in `netconf_evpn_issue` (the devices each one
involves in `netconf_evpn_issue_device`). An issue keeps its `first_seen` while it persists;
`last_seen` is the last resolve that still found it. Severities are `critical`, `warning` and
`info`.

| Check | Severity | Raised when |
|---|---|---|
| `neighbor-asymmetric` | warning | a member lists another monitored member as EVPN neighbour, the other does not list it back |
| `session-down` | critical | an overlay BGP session of a member towards another member is not established (state from core `bgpPeers` or `show bgp summary`) |
| `session-missing` | warning | a member lacks a session to a peer that the other monitored members have |
| `vni-flood-gap` | critical | a leaf carries a VNI but is missing from another carrier's flood list |
| `vni-stale-flood` | warning | a flood list points at a monitored member that does not carry the VNI |
| `vni-orphan` | warning | a VNI on a leaf has no remote VTEP at all |
| `vni-vlan-mismatch` | info | the same VNI maps to different VLAN tags on different leaves (legal) |
| `vni-irb-down` | critical | the anycast IRB of a VNI is not up on a gateway |
| `vni-irb-partial` | info | some carriers of a VNI have an IRB, others do not (legal for a gateway pair) |
| `esi-single-pe` | critical | an Ethernet segment with a local ESI-LAG has no peer PE (warning when the segment is only known from one remote PE) |
| `esi-df-disagree` | critical | the PEs name different designated forwarders, or more than one monitored side claims the DF role |
| `esi-mode-differs` | critical | all-active on one PE, single-active on the other |
| `esi-lag-down` | critical | the ESI-LAG is not up on a PE |
| `esi-unresolved` | critical | the ESI is not resolved on a PE |
| `esi-lacp-degraded` | warning | LACP members of the ESI-LAG are not distributing (`junos-lacp`) |
| `esi-no-aliasing` | warning | aliasing is disabled on one PE of a segment |
| `dup-mac` | critical | MACs are suppressed by duplicate-MAC detection on a leaf (the `dup-mac-instance` sensor) |
| `dup-mac-params` | warning | threshold, window or recovery time of duplicate-MAC detection differ between leaves of one instance |
| `mac-mobility` | warning | a MAC in the MAC database changed its active source more than *MAC mobility limit* times within one hour (opted-in leaves only; `moves`, `moves_recent`, `moves_since` on `netconf_evpn_mac`) |
| `route-count` | warning | a member receives no MAC routes from a neighbour that has local MACs in the same instance |
| `version-skew` | info | the monitored members run different software versions |
| `unknown-vtep` | warning | a member address does not belong to a monitored device |
| `tunnel-asymmetric` | warning | a member has a VXLAN tunnel to another member that has none back |
| `tunnel-errors` | warning | the `vtep.N` port of a tunnel counted errors or discards in the last poll |
| `member-not-polling` | warning | the NETCONF collection of a member is failing, so its data may be stale |

Alerting hooks, in the order you will probably use them:

- **Eventlog** entries of type `netconf-evpn` when an issue appears (severity error / warning /
  notice), changes severity or clears (ok), on every monitored device the issue involves; a
  fabric-level finding (unknown VTEP, version skew) is logged without a device.
- A **count sensor "EVPN fabric issues"** (`netconf-evpn-fabric-issues`, `limit: 0`) on every
  monitored member with the number of critical and warning issues that involve it, recorded by
  the member's own poll (so it reflects the resolve before that poll). Alert on it like on
  any sensor (*Alerting* below), graph it on the health tab.
- **Advanced-SQL rules** on `netconf_evpn_issue` for one alert per fabric or per check
  (examples below).

`lnms netconf:fabric --checks` prints the open issues on the command line.

## Alerting

Sensors work with the normal rule builder; the metric tables need *Override SQL* on the
*Advanced* tab of the rule (the statement goes into *Query*, `?` is replaced by the device
id, any returned row raises the alert). The builder rules below are written the way the
rule list shows them. `sensors.sensor_alert = 1` keeps the per-sensor *alert* toggle of
the health tab working; drop it if you do not use that toggle.

**Duplicate MACs (count sensor).** One alert per EVPN instance that reports duplicate MACs;
the sensor already carries `limit: 0`, so the generic *Sensor over limit* rule from the
collection fires too. Use `netconf-junos-evpn-dup-mac-total` instead for one alert per
device.

```
sensors.sensor_class = "count" AND sensors.sensor_type = "netconf-junos-evpn-dup-mac-instance"
  AND sensors.sensor_current > 0 AND sensors.sensor_alert = 1 AND macros.device_up = 1
```

Template line: `{{ $value['sensor_descr'] }}: {{ $value['sensor_current'] }} duplicate MACs`
inside the `@foreach ($alert->faults as $key => $value)` loop.

**EVPN fabric issues (count sensor).** One alert per fabric member with critical or
warning issues involving it (the *Checks* tab of the fabric lists them):

```
sensors.sensor_type = "netconf-evpn-fabric-issues" AND sensors.sensor_current > 0
  AND sensors.sensor_alert = 1 AND macros.device_up = 1
```

**Fabric checks by check or severity (Override SQL on `netconf_evpn_issue`).** The issue table
is fabric-level; join `netconf_evpn_issue_device` to alert on the devices involved (the rule
then fires per device), or leave the device out for one alert per fabric on any device that
runs the plugin:

```sql
-- critical issues involving this device, with the check and the message for the template
SELECT netconf_evpn_issue.check, netconf_evpn_issue.severity, netconf_evpn_issue.message, netconf_evpn_issue.first_seen
FROM netconf_evpn_issue JOIN netconf_evpn_issue_device ON netconf_evpn_issue_device.issue_id = netconf_evpn_issue.id
WHERE netconf_evpn_issue_device.device_id = ? AND netconf_evpn_issue.severity = 'critical'
-- one check only, e.g. flood-list gaps anywhere in the fabric of this device
SELECT i.check, i.message FROM netconf_evpn_issue i
JOIN netconf_evpn_fabric_member m ON m.fabric_id = i.fabric_id
JOIN netconf_evpn_vtep v ON v.vtep_ip = m.vtep_ip
WHERE v.device_id = ? AND i.check = 'vni-flood-gap'
```

**ESI-LAG down (state sensor).** The `state_sensor_critical` macro joins the state
translations, so the rule fires on the state the definition marks `generic: 2` (`Down`)
and stays quiet on `Unknown`:

```
sensors.sensor_type = "netconf-junos-evpn-esi-lag-status" AND macros.state_sensor_critical = 1
  AND sensors.sensor_alert = 1
```

Replace the first condition with `sensors.sensor_type LIKE "netconf-%"` (operator *begins
with*) for one rule that covers every critical netconf state: ESI unresolved, EVPN interface
or IRB down, LACP member not distributing, LDP session, RPKI cache, VRRP group, SRX node.
The template gets `{{ $value['state_descr'] }}` from the join.

**BGP peer flapped (Override SQL on `netconf_metrics`).** `flaps` is a counter and the
table holds only the last value, so SQL cannot compute an increase; the direct signal is
`uptime`, the seconds since the session came up. A peer that has flapped at least once and
whose session is younger than 15 minutes:

```sql
SELECT netconf_metrics.device_id, netconf_metrics.descr,
       JSON_EXTRACT(`values`, '$.flaps') AS flaps, JSON_EXTRACT(`values`, '$.uptime') AS uptime,
       JSON_UNQUOTE(JSON_EXTRACT(labels, '$.state')) AS state
FROM netconf_metrics
WHERE netconf_metrics.device_id = ? AND definition = 'junos-routing' AND mapping = 'bgp-peer'
  AND JSON_EXTRACT(`values`, '$.flaps') > 0 AND JSON_EXTRACT(`values`, '$.uptime') < 900
```

The alert clears by itself once the session is 15 minutes old; the flap history is on the
peer's graph on the metrics page. `values` needs the backticks (reserved word); numeric
fields are stored as numbers, labels as strings (`JSON_UNQUOTE`).

More one-liners of the same kind:

```sql
-- BGP peer not established (junos-routing/bgp-peer)
SELECT * FROM netconf_metrics WHERE netconf_metrics.device_id = ? AND mapping = 'bgp-peer'
  AND JSON_UNQUOTE(JSON_EXTRACT(labels, '$.state')) != 'Established'
-- configuration committed in the last 10 minutes (junos-system/uptime)
SELECT * FROM netconf_metrics WHERE netconf_metrics.device_id = ? AND mapping = 'uptime'
  AND JSON_EXTRACT(`values`, '$.config_age') < 600
-- the plugin cannot reach the device: three failed sessions in a row (the status page shows the error)
SELECT devices.hostname, netconf_device_status.consecutive_failures, netconf_device_status.last_error
FROM netconf_device_status JOIN devices USING (device_id)
WHERE netconf_device_status.device_id = ? AND netconf_device_status.consecutive_failures >= 3
```

Rows in `netconf_metrics` keep the values of the last successful poll, so a device in
back-off alerts through the last rule rather than through stale metric rows.

## Upgrade and uninstall

**Upgrade.** `daily.sh` re-installs every plugin listed in `composer.plugins.json` at the
version constraint `plugin:add` recorded (`^1.0` for a 1.x install), so patch and minor
releases arrive with the regular LibreNMS update. To move on purpose, or to a specific
version, run `plugin:add` again, then the migrations:

```bash
./lnms plugin:add saffer-it/librenms-netconf            # newest release within the constraint
./lnms plugin:add saffer-it/librenms-netconf 1.2.1      # a specific version
./lnms migrate
./lnms route:cache                                      # new routes reach a cached installation
./lnms view:clear                                       # new pages replace the compiled old ones
```

Read the *Upgrade notes* of the release in `CHANGELOG.md` first; nothing has to be
deleted for an upgrade, the RRDs heal themselves (see *What lands where*).

**Disable.** `./lnms plugin:disable netconf` stops polling and discovery, hides the pages
and the commands and keeps all data; `plugin:enable netconf` resumes.

**Uninstall.** `./lnms plugin:remove saffer-it/librenms-netconf` only removes the package.
Everything the plugin stored stays behind:

| What | Where |
|---|---|
| sensors with `poller_type = netconf` (values, state translations) | `sensors` table, `rrd/<host>/sensor-<class>-netconf-*.rrd` |
| custom and per-port metrics | tables `netconf_metrics`, `netconf_port_metrics`, files `rrd/<host>/netconf-*.rrd` |
| per-device status and back-off | table `netconf_device_status` |
| per-device switches and credential overrides | `devices_attribs` rows `netconf_enabled`, `netconf_queues`, `netconf_username`, … |
| module scheduling | config keys `poller_modules.netconf`, `discovery_modules.netconf` |
| the plugin's migrations | rows in the `migrations` table |
| global settings including the encrypted password | row `netconf` in the `plugins` table (LibreNMS deletes it itself once the package is gone) |

`netconf:uninstall` removes all of that in an order a concurrently running poller cannot
undo (plugin disabled and module keys erased first, rows and files next, tables and
migration rows last). It stays available while the plugin is disabled:

```bash
./lnms netconf:uninstall                  # lists what would be deleted, changes nothing
./lnms netconf:uninstall --purge          # asks once, then deletes; --keep-rrd keeps the files, --force skips the question
./lnms plugin:remove saffer-it/librenms-netconf
```

Alert rules that reference the plugin's sensors or tables are listed by the command and
left for you to delete (rules on `netconf_metrics` fail once the table is gone). Also
untouched: the SSH key and `known_hosts` files and the user definitions directory. To
start over rather than uninstall, run `--purge`, then `plugin:enable netconf`, `migrate`
and enable the devices again.

## Development

```bash
composer install
composer check          # php-cs-fixer, phpstan, pest
```

`composer.lock` is committed and resolved for PHP 8.2 (`config.platform.php`), so every
CI job and every checkout installs the same dependency set; run `composer update` on
purpose only. LibreNMS resolves the plugin against its own lock file, so the platform pin
has no effect on installations.

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
