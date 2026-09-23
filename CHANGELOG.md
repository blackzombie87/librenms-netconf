# Changelog

## Unreleased

Fixes from the review of 2026-09-23 (internal plan F6); the first three are fixes to 1.1.0:

- A poll whose collection succeeded but whose storage then failed no longer reports itself as
  the last successful run: *Last OK* moves only once the writers are through, and the failure
  counter continues the streak the run started with instead of restarting at 1 from the
  success the status row had already written — so the back-off no longer collapses to its
  first step. If the fabric resolve of that run had already cleared the "member not polling"
  issue for the leaf (it reads the status row), the resolve runs again after the failure is
  recorded, so the issue comes back.
- The *month* control of the NETCONF metrics section drew a day: the period pattern ended at
  one-letter units, so `-1mo` fell back to `-1d` (`-1m`, which it did accept, is a *minute* in
  LibreNMS). Both metric pages take the unit list core parses — `s m h d w mo y`, with a sign —
  through one `Support\GraphPeriod`, and the port Plugins tab, which used to pass the query
  string through unchecked, now reads the same token as the device tab.

UI re-home (internal plan §8): NETCONF per-device detail moves from the overview into its
own place, the overview keeps a summary.

- The device overview shows one NETCONF panel: polling state, fabric membership and the
  stored counts (sensors with the critical count, metric rows, ESI-LAGs, definitions) with a
  link to the per-device page. The metric tables and the second "EVPN multihoming" panel are
  gone from the overview; Health graphs the sensors, Neighbours lists the ESI links. On a core
  with `<x-device.overview.panel>` (26.8+) the panel is that component, older cores get the
  same markup.
- NETCONF is a device tab: `/device/{id}/netconf` with the sections status (default), metrics,
  ESI-LAGs and edit, inside the device header and tab bar next to Health and Neighbours.
  Credentials and the polling switch are on the edit section, admin only (403 otherwise); a
  viewer with access to the device sees status, metrics and ESI-LAGs. The old
  `/plugin/netconf/device/{id}` and `…/metrics` URLs redirect to the tab. The tab is
  registered through core's `PageTabs::$tabsClasses` (no plugin hook exists for device tabs
  yet); when a core release changes that seam the plugin logs a warning once and the
  standalone pages come back in place of the redirects.
- The Plugins tab of a port shows the counter values and requests its graphs only when a
  fold-out is opened, like the metrics section; it used to render every graph (one rrdtool run
  each) on page load.
- List pages: the status list's last result reads `20 ok / 7 skipped / 0 failed · 141 sensors ·
  8.0 s` with a marker when commands failed, the definitions column is a count that folds out
  to the names, fold-outs look like clickable headings and the mapping fold-outs like panels,
  `/plugin/netconf` redirects to `/plugin/netconf/status` instead of rendering the list a
  second time.

Fabric view at scale (internal plan G18):

- The EVPN fabric resolve no longer costs a set of queries per monitored member. It ran seven
  per device — the leaf's own VTEP addresses and router-ids, the IRB and tunnel checks, its
  ports, and the tunnel, ESI and `links` rows to correct — which a poll of any one leaf paid
  for the whole fabric. Those are grouped queries now, the remote-MAC sources are only written
  where the resolved device changed, and the `evpn-esi` links are inserted and deleted in one
  statement each. Measured in the feature harness on a synthetic full mesh: 24 members cost 2
  queries more than 4 (it used to be 142 more); one real leaf 94 instead of 102, with the
  stored fabric byte for byte the same. A new feature test keeps the per-member budget.
- Which router-id a device is stored under is now the lowest one rather than whatever the
  database returned first, so it cannot change from poll to poll on a device with several.

Internal tidy-up, no behaviour change:

- `Extractor::sensors()` and `::metrics()` share the indexed-row iterator they both opened
  with (rows, index, duplicate-index warning); what happens to a row afterwards stays apart.
- One `Support\Mac` helper for the two MAC notations (Junos writes separators, LibreNMS
  stores 12 hex digits, the pages group them again) in place of three copies of the same
  `preg_replace`/`str_split` pair in the extractor, the fabric checks and the MAC search.
- `EvpnSessions::discover()` returns the sessions and the metric rows it read together
  (`EvpnSessionSet`) instead of leaving the rows in a static property for the next caller.

Development:

- PHPStan runs with the Larastan extension (level 6, clean): Eloquent property access,
  relation names and `view()` arguments are checked now. Found on the way: the metric row
  models share an abstract `NetconfMetricRow` (columns, casts and `dataSources()` used to be
  written out twice) and `Device::attribs()` was missing from the LibreNMS stub.
- The analysis can use a real LibreNMS instead of that stub: `composer analyse:librenms` with
  `LIBRENMS_PATH` set boots the checkout and analyses against core's own models, interfaces and
  commands. CI runs it in the feature job, which already has a LibreNMS, so a stub that drifts
  away from core now fails the build. `composer analyse` keeps working on a bare checkout.
- The real-LibreNMS analysis found two things the stub had hidden: `netconf:device` used the
  credential-override trait without declaring its options (the device lookup is its own
  `FindsDevice` trait now, and the three commands that do declare them are checked against
  their signatures), and `Modules\Netconf::dependencies()`/`dump()` had untyped array returns.

Documentation:

- README *Distributed pollers*: which settings name node-local files (private key,
  `known_hosts`, definitions directory), the shared `APP_KEY`, and the fabric resolver's
  cache lock, which only serialises across nodes with a shared cache store.

## 1.1.0 – 2026-09-22

Fixes from the full software review of 2026-09-22 (internal plan item F5):

- The fabric checks judge this poll: YAML sensors and the run's outcome are stored before
  the fabric resolve, the "EVPN fabric issues" sensor after it. `dup-mac` issues used to
  open and clear one poll late, the border role and `member-not-polling` followed the
  previous poll.
- Sensor indices are fitted to core's 128-character column at extraction time (metrics: 191):
  a longer index keeps its first 119 characters plus `~` and eight hex digits of its sha1, so
  two long indices stay two sensors and discovery, polling and the RRD name agree. A
  129-character index used to abort discovery. `netconf:validate --replay` warns about it.
- Fabric issue keys are digests (`check|sha1(subject)`); a long subject (an ESI plus
  instance names) used to make every later resolve of that fabric fail on the unique index.
  Existing rows are re-keyed silently. The resolver writes its snapshot in one transaction.
- The plugin's own `evpn-esi` rows in the core `links` table no longer count as LLDP evidence
  for underlay links between two monitored PEs.
- Per-device passwords and key passphrases are stored byte for byte; only usernames, ports and
  paths are trimmed. The web forms keep the whitespace of secret fields too.
- A writer that throws during storage (a schema limit, a lost database connection) is recorded
  as a failed run with back-off and an eventlog entry instead of leaving the run half stored.
- `poll_budget` is documented as what it is: a scheduling budget checked between commands.
- Fixtures: the positive duplicate-MAC sample (one suppressed VRRP virtual MAC) and the
  per-MAC `show evpn database mac-address … extensive` reply from an EX4650 on 23.4R2-S8.7.
- Tests and CI: 40 feature tests (module scheduling matrix, service-level check transitions,
  underlay evidence, index identity, issue store, secrets) run on GitHub against LibreNMS
  26.7.0 next to the unit suite; the workflow is linted with actionlint.

Gaps closed on the way to 1.1 (internal plan §6.2):

- Transport `auto`, now the default: one SSH login on port 22, the `netconf` subsystem is
  requested on that connection and exec channels are used when the server refuses it. The
  status row records the negotiated mode, `netconf:test` prints `netconf (auto)` / `cli
  (auto)` and the refusal reason (both verified on the EX4650 by toggling `netconf ssh`). Motivation (measured on the EX4650 with the recommended
  `deny-commands` class): every exec channel starts a new CLI and pays the class evaluation,
  22 commands took 45 s over exec and 13 s over the subsystem; 19 s vs. 14 s without
  `deny-commands`. Existing installs with a saved `cli`/`netconf` setting are unaffected;
  the blank "(default)" choice now means `auto`. `netconf:device --set-transport` and the
  device/status forms accept `auto`.
- `filter:` on cli commands (G1): a pattern spliced in at `{filter}` or appended, e.g.
  `filter: 'et-*'` on a copy of `junos-interfaces` turns the 1 MB reply of a 48-port leaf
  into the uplinks only. Part of the command identity and of the replay fixture name.
- Bulk enable (G7): `lnms netconf:device --group=<name|id> [--os=<os>]` applies `--enable`,
  `--disable` and the credential overrides to every device of a device group and/or os and
  lists them; the status page gets an *Enable per device group or os* form for admins.
- Login class verified on an EX4650 (R4): `permissions view` alone runs every shipped
  definition over both transports and with key auth; README now recommends that plus a
  `deny-commands` regex and explains why `allow-commands` cannot restrict. `rfc-compliant`
  does not enable `base:1.1` on 23.4R2 (G5 stays unobserved).
- Metrics page (G12): every mapping and the port table are collapsed fold-outs, graph images
  are created on open instead of shipped as `<img>` tags (99 graph renders per page view on
  a 48-port leaf before; the HTML shrank from 397 kB to 248 kB, of which 73 kB is the
  layout). Expand/collapse all links.
- Fabric issues sensor (F4a): recorded after the run's own fabric resolve instead of one poll
  behind it, and a poll writes 0 while the fabric view is switched off or after the device
  left the fabric, so `limit 0` alert rules clear instead of keeping the last count until
  the next discovery. Discovery still drops the sensor of a device without membership.
- Missing-session check and BGP tab (F4a): a peer is counted under the member address of its
  device, so members that reach the same node on its VTEP and on its router-id no longer
  split the count or flag each other as missing.
- Session hygiene on the interactive callers (F4a): the device page's *Test* button, the
  status page's run form and `netconf:test` close the SSH session on every path, not only
  on success, and `netconf:test` also hangs up when the login succeeded but the hello or
  the subsystem request failed. `netconf:run` retries once after two seconds when the
  device's SSH connection rate limit closes the socket ("Error reading SSH identification
  string").
- Bulk enable form (F4a): nothing is pre-selected any more. *Apply* without choosing an
  action is rejected instead of enabling every device of the only os in the install, and a
  selection without device group and os asks for confirmation first.
- VNIs tab (F4a): the table is paged (100 rows, the filter and the search are kept in the
  page links), opens on the rows with issues when the fabric has any, and its device links
  no longer carry core's 2 kB hover tooltip per row. One page of a 285-VNI leaf went from
  871 kB to about 160 kB.
- Status page (F4a): the stored run summary counts the sensors that were actually recorded,
  including the fabric issues sensor, instead of the YAML sensors only.
- Custom metrics (F4a): an index longer than the column width is truncated once, so the row
  and its RRD file keep the same name.
- Internal (F4a): the duplicated host-key description, metric orphan prune, BGP peer metric
  queries and EVPN instance / collector-failure lookups each live in one place now. The BGP
  overlay tab reuses the rows the session discovery already read instead of querying them
  again.
- Tests (F4a): the Feature suite grew to 28 tests — the poller module end to end against
  recorded replies (discover, poll, dump, cleanup, and a failed connect), the metric writers
  against the database and a datastore, `RrdLayout` against a real rrdtool, and
  `netconf:uninstall --purge` on a seeded device. A CI job runs the suite against a pinned
  LibreNMS tag with a MariaDB service.

EVPN fabric checks, phase F4 (internal plan §7.5):

- Consistency checks over every fabric at the end of each resolve: asymmetric neighbours,
  overlay sessions down or missing, flood-list gaps / stale entries / orphans, VLAN tag
  mismatch, IRB down or partial, ESI single PE / DF disagreement / mode / LAG down /
  unresolved / LACP / aliasing, duplicate MACs and differing detection parameters, MAC
  mobility, missing MAC routes, version skew, unknown VTEPs, tunnels without reverse or with
  errors, members whose collection fails. Findings live in the new tables
  `netconf_evpn_issue` and `netconf_evpn_issue_device` with a stable `first_seen`.
- Eventlog entries of type `netconf-evpn` on every involved device when an issue appears,
  changes severity or clears; nothing between polls.
- Count sensor **EVPN fabric issues** (`netconf-evpn-fabric-issues`, limit 0) on every
  monitored member with the critical and warning issues involving it, recorded by the
  member's own poll.
- **Checks** tab on the fabric pages with filters and a legend; issue counts on the fabric
  list, the overview and the device badge; `lnms netconf:fabric --checks`.
- MAC move counter on `netconf_evpn_mac` (`moves`, `moves_recent`, `moves_since`) and the
  setting *MAC mobility limit* (`evpn_mac_moves`, default 5 per hour, 0 = off).
- `junos-evpn` stores the duplicate-MAC detection threshold, window and recovery time as
  labels of the instance metric.
- Alert-rule examples for the sensor and the issue tables in the README.

Polish from the internal F3 review (plan item F3a):

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

EVPN fabric view, phase F1 (internal plan §7), feature-flagged by the new *EVPN fabric view*
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

Polish from the internal F1 review (plan item F1a):

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

Fixes from the external review of 1.0.0 (internal review notes).

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
- Docs: the implementation plan (§6 work list, §7 EVPN fabric view design) and the review
  notes are maintained outside the public repository.

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
