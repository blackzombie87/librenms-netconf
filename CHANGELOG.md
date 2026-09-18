# Changelog

## Unreleased (1.0.0 candidate)

First release. LibreNMS plugin that runs `show … | display xml` (SSH exec) or NETCONF
RPCs on Junos devices and maps the replies onto native sensors, per-port metrics and
custom metrics through YAML definitions. See `README.md` for what is shipped.

### Fixed before release (review of 2026-09-18)

- RRD data source drift: metric and port RRDs are now created from every numeric field
  of the mapping in YAML order and updated with `U` for missing values. Before, the data
  source set depended on the first reply, and later polls with more fields failed with
  "found extra data on update argument" (seen on the NTP mappings). Label fields carry
  `type: string` and never become data sources.
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

### Upgrade notes

- Run `lnms migrate` (new `netconf_metrics.types` column).
- Delete `rrd/<host>/netconf-junos-ntp-*.rrd` (and any other `netconf-*.rrd` created
  before this change whose mapping gained numeric fields); they are recreated with the
  full data source set on the next poll. `rrdtool info <file> | grep '^ds\['` lists the
  current data sources.

### Known gaps

- EVPN multihoming peers are metrics only (`junos-evpn-esi`), not LibreNMS neighbours.
- No license expiry sample yet (`junos-license` has licensed/used/needed/validity only).
- `junos-ldp`, `junos-rpki`, `junos-vrrp` use element paths from junos_exporter with
  synthetic fixtures; not verified on a device running those protocols.
- No feature tests against a LibreNMS application; writers and HTTP controllers are
  covered indirectly (pure logic is unit-tested, including data source stability).
- `composer.lock` is not committed; CI installs unpinned dependencies.
