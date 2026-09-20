# Changelog

## Unreleased (1.1)

EVPN fabric view, phase F1 (`docs/PLAN.md` §7), feature-flagged by the new *EVPN fabric view*
setting (`evpn_fabric`, default off).

- New YAML mapping kind `tables:`: rows of a reply are written to the plugin's
  `netconf_evpn_*` tables (neighbor, esi, vni, vni_vtep, tunnel, mac) with fixed key columns
  and column types, merged on the key across mappings and pruned per device.
- New tables `netconf_evpn_{neighbor,esi,vni,vni_vtep,tunnel,mac,vtep,fabric,fabric_member,underlay_link}`.
- `junos-evpn-fabric` fills the per-leaf tables (four extra commands per leaf, the remote
  MAC table every third poll); `junos-evpn-fabric-mac` the MAC database (opt-in per device,
  attribute `netconf_evpn_mac`).
- `FabricResolver`: VTEP address → device, roles (leaf, spine, L3 gateway, border flag),
  union-find over EVPN neighbours, ESI peers, tunnels, EVPN BGP sessions and confirmed
  underlay links → fabrics and members (manual `pinned` members survive); underlay links from
  core ipv4/BGP/OSPF/LLDP tables. Runs after every leaf poll under a cache lock.
- `lnms netconf:fabric [--resolve] [--links]` prints the resolved fabrics.

Polish from the F1 review (`docs/REVIEW-2026-09-f1.md`, plan item F1a):

- One fabric member per device: a device's addresses (source VTEP, router-id) pool their
  role evidence and only the first becomes the member; `vtep` rows no longer in the graph
  and automatic fabrics without members are deleted on every resolve.
- Deleting a device nulls its `vtep` rows, drops its underlay edges and MAC source links and
  recomputes the fabrics; the module `dump()` includes the `netconf_evpn_*` tables keyed by
  their natural key.
- Rows of a table that no matched definition fills any more are pruned on the next poll
  (clearing `netconf_evpn_mac` removes the device's MAC rows); the resolver only runs when
  the device wrote or pruned table rows. While the *EVPN fabric view* setting is off nothing
  is collected and nothing is deleted.
- `junos-evpn-fabric` replayed against the MX204 gateway sample (IRB rows, leaf ESIs only);
  `df_ip`/`bdf_ip` keep IPv6 DF addresses.
- `netconf:uninstall` lists per-table `netconf_evpn_*` row counts and scans every table
  that references a device; `netconf:fabric --resolve` takes the resolver lock and sorts
  IPv6 members correctly; README storage table gains the `tables` row.

## 1.0.1 – 2026-09-18

Fixes from the external review of 1.0.0 (`docs/REVIEW-2026-09-v1.0.0.md`).

- One poller log line per run summarises the RRD layout work (files verified, data sources
  added, rrdtool failures) instead of only logging appended data sources per file.
- `netconf:uninstall --purge` resolves rrdcached listings (`/<host>/<file>` or bare names)
  against the device's RRD directory before reporting a file as not local.
- `junos-vrrp`: the degraded-group count compares the state for equality with master/backup
  instead of a substring test.

## 1.0.0 – 2026-09-18

First release. LibreNMS plugin that runs `show … | display xml` (SSH exec) or NETCONF
RPCs on Junos devices and maps the replies onto native sensors, per-port metrics and
custom metrics through YAML definitions. See `README.md` for what is shipped.

### Fixed after the first live samples from other devices (2026-09-18)

- `junos-evpn`: EVPN L3 gateways (MX204 sample) list one type-5 ESI per anycast IRB with an
  empty status and no remote PE. Those were treated as ESI-LAGs and would have produced one
  "Unknown" resolution state per IRB and an "ESIs without remote PE" alarm equal to the IRB
  count. IRB-backed ESIs are now excluded from the ESI-LAG sensors, the ESI metric and that
  count; a new `count` sensor reports the number of anycast gateway IRBs.
- `junos-vrrp`: a dual-stack interface reports one row per address family with the same
  interface/group, which collided on one sensor index. The IPv6 row is now suffixed `/v6`
  and the VIP field skips the link-local address.
- Fixtures: the synthetic LDP, RPKI and VRRP samples are replaced by real (anonymised) replies
  from a Junos 22.2 router; EVPN fabric samples from the EX4650 (VXLAN source/remote/remote
  MAC table, `show interfaces vtep`, `show evpn database`, `show bgp neighbor`, `show vlans`,
  neighbour-info) and from an MX204 L3 gateway (`-mx` variants) were added; the license
  "none installed" and the empty ip-prefix / mac-ip replies cover the negative cases.
- Docs: the implementation plan (`docs/PLAN.md`, with the §6 work list and the §7 EVPN fabric
  view design) and the September review (`docs/REVIEW-2026-09.md`) now live in this repo.

### Fixed before release (review of 2026-09-18)

- RRD data source drift: metric and port RRDs are now created from every numeric field
  of the mapping in YAML order and updated with `U` for missing values. Before, the data
  source set depended on the first reply, and later polls with more fields failed with
  "found extra data on update argument" (seen on the NTP mappings and 24 of 30 port
  files, whose FEC counters exist on some ports only), or, with the same count in a
  different order, wrote values into the wrong data source without any error. Label
  fields carry `type: string` and never become data sources.
- Existing RRDs heal themselves: the data source order of every file is verified once
  (`rrdtool info`), missing fields are appended with `rrdtool tune DS:…` (history kept)
  and the verified order is stored on the row, so later polls cost nothing extra. A
  changed YAML field set is handled the same way; a file that cannot be tuned is
  written with the data sources it has and a warning names the fields not recorded.
- Devices that matched no definition at discovery were never re-matched while polling.
- Definitions were cached for the process lifetime; edits are now picked up.
- The web "run a show command" form accepted `;`, pipes, multi-line input and
  `show configuration`.
- Port metric rows of vanished or deleted ports were never removed; the module's dump
  (module test data) now includes port rows.
- Graphs of hostnames containing colons (IPv6 literals) were broken.
- Combined graphs silently dropped everything after the 25th row; now stated on the
  image and the metrics page.
- Metric and port rows are written with batched upserts (roughly 500 fewer statements
  per poll on a 48-port EVPN leaf).
- Optional host key verification against an OpenSSH `known_hosts` file
  (setting `known_hosts`, `--known-hosts`); `netconf:test` prints the fingerprint.
- Parser hints (bare-word `index:` expressions) are shown by `netconf:validate` and the
  definitions page; the unused sensor `entity:` key was removed.
- `netconf_metrics.types` stores the RRD data source types per row (migration).
- `lnms netconf:uninstall [--purge] [--keep-rrd] [--force]` lists and removes everything the
  plugin stores in LibreNMS (sensors and their RRDs, the three tables and their RRDs, device
  attributes, module config keys, migration rows); it stays available while the plugin is
  disabled so it can run right before `plugin:remove`. README section *Upgrade and uninstall*.
- `lnms plugin:disable netconf` now stops the poller/discovery module; before, the persisted
  module keys kept it running with the plugin disabled.
- `composer.lock` is committed and resolved for PHP 8.2 (`config.platform`), so CI
  installs the same dependency set on every PHP version; LibreNMS resolves the plugin
  against its own lock, so installations are unaffected.

### Upgrade notes

- Run `lnms migrate` (new `netconf_metrics.types` column; stored data source orders are
  reset so every file is verified once on the next poll).
- The first poll after the upgrade runs `rrdtool info` per metric/port RRD and appends
  missing data sources (`rrdtool tune`, needs rrdtool 1.5 or newer, works through
  rrdcached). Nothing has to be deleted; the log lists the files that gained data sources.

### Known gaps

- EVPN multihoming peers are metrics only (`junos-evpn-esi`), not LibreNMS neighbours.
- No license expiry sample yet (`junos-license` has licensed/used/needed/validity only).
- No feature tests against a LibreNMS application; writers and HTTP controllers are
  covered indirectly (pure logic is unit-tested, including data source stability).
