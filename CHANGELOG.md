# Changelog

## Unreleased (1.1)

Polish from the F3 review (`docs/REVIEW-2026-09-f3.md`, plan item F3a):

- Fabric list and overview count VNIs, ESIs and instances distinct over the monitored
  members (array union on 0-based lists dropped the second member's entries); the per-device
  EVPN figures live in one `Fabric\View\DeviceStats` helper shared by the list, overview,
  members tab and device badge.
- Overlay arcs on the topology and the "EVPN sessions down" badge canonicalise a neighbour or
  peer listed by its router-id to the member address of its device, so a leaf whose VTEP
  differs from its router-id no longer loses arcs or is undercounted.
- The device badge links to the fabric pages only for users with global read; others get
  the figures as text.
- Deleting a device removes its unpinned fabric memberships and always recomputes the
  fabrics, also while the fabric setting is off. A fabric rename saved twice within one
  second is no longer a 404.
- A required command the device does not answer (`<output>`, `<xnm:warning>`, rpc-error)
  fails the run like a lost connection: status row, back-off and eventlog. Both EVPN
  definitions mark `show evpn instance extensive` optional (every Junos device matches
  them). Definitions sharing a command run it with `every = min` and required if any use is
  required; `netconf:validate` prints a hint when they disagree.
- When no definition matches a device the writers still run: sensors synced, metric and port
  rows pruned, the EVPN tables emptied and the device forgotten by the resolver. Metric and
  port rows of mappings no matched definition contains are deleted, and sensors of a class no
  definition produces any more go at discovery.
- `discovery_modules.netconf` is persisted against the config table (the in-memory config hid
  a missing row, and `device:discover -m netconf` then ran only `core`).
- `tunnel-nexthop` no longer writes `tunnel.ifname` (the kernel IFL from `show interfaces
  vtep` owns it); MAC search loads its device and VTEP lookups in one query each; overlay
  session discovery exists once (`Fabric\EvpnSessions`) for the resolver and the BGP tab.
- First feature tests (`tests/Feature/`, `phpunit.feature.xml`, LibreNMS-bootstrapped): fabric
  pages 302 / 200 / 403, `TableWriter` merge and prune, `EsiLinkWriter` sync, `FabricResolver`
  forget/run round trip. Skipped without a LibreNMS installation.

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

Phase F2 — EVPN multihoming peers (plan §3.8):

- Core `links` rows with `protocol = evpn-esi`, one per local ESI-LAG and remote PE, written
  by the fabric resolver: peer device via the vtep table, peer LAG via the same ESI on the
  peer's side, BGP description or VTEP address as the name of an unmonitored peer. Rows keep
  their id across resolves; stale rows are deleted. New setting *ESI peers as neighbours*
  (`evpn_links`, default on, acts only with the fabric view); off deletes the rows,
  `netconf:uninstall` lists and purges them, device deletion removes them.
- **EVPN multihoming** panel on the device overview (first ten ESI-LAGs) and table on the
  plugin device page (all): local LAG, peer, peer LAG, mode, DF/BDF, LAG state, remote MACs;
  ESIs without a remote PE and peers with a differing LAG state are flagged.

Phase F3 — fabric pages (plan §7.4, §7.6), *Plugins → EVPN fabrics* while the fabric view is on:

- Fabric list (`/plugin/netconf/fabrics`): members by role, instances, VNIs, ESIs, MACs and
  health badges (EVPN sessions down, ESI-LAGs degraded, duplicate MACs, VNIs without flood
  list, unknown VTEPs, members not polling). Admins can rename a fabric and add notes.
- Fabric page (`/plugin/netconf/fabric/<id>/<tab>`): **Overview** with the figures and the
  topology map; **Members**; **BGP overlay** (core bgpPeers with the evpn SAFI merged with the
  plugin's `show bgp summary` rows and the EVPN route counts per neighbour, peers that other
  members have flagged as missing); **VNIs** (VLAN tag per leaf, carriers, flood-list gaps and
  stale entries between monitored carriers, orphans, anycast IRBs; filter, issues-only);
  **ESI / multihoming** (one row per segment with every PE, DF/BDF, aliasing, LACP, flags for
  single PE, DF disagreement, mode mismatch, LAG down, unresolved); **Tunnels** (vtep.N per
  remote VTEP with the core port's traffic and errors, reverse-tunnel check); **MACs**.
- Topology map (inline SVG): gateways and spines on top, leaves grouped by site (device
  location, inherited by ESI partners) or ESI pair; underlay links coloured by BGP/OSPF state
  (WAN dashed, LLDP-only grey, stubs for unresolved far ends), overlay neighbour arcs and ESI
  pair brackets with toggles; nodes link to the device.
- MAC search (`/plugin/netconf/evpn/mac?q=`): MAC in any notation or prefix, IP or VNI over
  the opted-in leaves' EVPN database (local port, ESI with PEs, remote VTEP → device) plus
  core FDB and ARP rows; also as a tab scoped to one fabric.
- Device overview and plugin device page show the device's fabric, role, VTEP and counts
  (VNIs, ESI-LAGs and DF count, EVPN neighbours, tunnels) with links into the tabs.

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
