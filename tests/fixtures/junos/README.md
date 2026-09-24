Anonymised `| display xml` samples collected 2026-09-18 from Junos 23.4R2-S3 (EX4650 EVPN-VXLAN leaf)
and 24.4R2-S3 (SRX chassis cluster). Hostnames, IPs, ASN and MACs replaced with documentation values;
structure and counters are original. Peer lists were shortened. Intended as Pest fixtures for
saffer-it/librenms-netconf. EVPN commands added 2026-09-18 (instance list, ESIs, neighbours and bridge domains shortened).

2026-09-18, Phase 1 live test on the same EX4650 (now 23.4R2-S7.4, single-member Virtual Chassis):
`show-version-multi-re.xml` (multi-routing-engine-results wrapper, package list shortened),
`show-system-alarms.xml` (four license alarms), `show-chassis-alarms.xml` (`no-active-alarms`),
`show-virtual-chassis-status.xml`. Payload size reference: `show interfaces extensive` on this
74-physical-interface switch returned 982 kB in 3.2 s over the cli transport.

File names follow `FixtureReplay::slug(command)` (`show route summary` → `show-route-summary.xml`) so the
directory can be replayed with `lnms netconf:validate --replay=tests/fixtures/junos`; variants carry a
suffix (`show-chassis-cluster-status-not-enabled.xml`). The L3-context and chassis-alarm samples are the
empty/none cases; the duplicate-MAC sample is positive since 2026-09-22 (below), its empty case is
`show-evpn-database-state-duplicate-empty.xml`.

Tier 2 samples (2026-09-18): `show-ntp-status.xml`, `show-ntp-associations.xml` (peer renamed),
`show-krt-queue.xml`, `show-system-license-usage.xml`, `show-system-commit.xml` (four entries, user
renamed), `show-system-uptime.xml` (multi-RE), `show-lacp-interfaces.xml` (two aggregates) are
captures from the EX4650. `show-ldp-neighbor.xml`, `show-ldp-session.xml`, `show-validation-session.xml`,
`show-validation-statistics.xml` and `show-vrrp-summary.xml` are **synthetic**, built from the element
names in junos_exporter's `pkg/features/{ldp,rpki,vrrp}/rpc.go` (MIT) — the leaf runs none of these
protocols; the `-not-running.xml` variants are its real `<output>` / `<xnm:warning>` replies.

EVPN fabric samples (2026-09-18, same EX4650 on 23.4R2-S7.4, captured with `netconf:run` from the Docker
instance; plan §7.2): `show-mac-vrf-forwarding-vxlan-tunnel-end-point-{source,remote,remote-mac-table}.xml`
(5 VNIs / 3 remote VTEPs / 2 bridge domains kept), `show-interfaces-vtep.xml` (source + 3 remote IFLs),
`show-evpn-database.xml` (9 MACs: ESI-, VTEP- and local-IFL-sourced), `show-evpn-database-extensive.xml`
(2 MACs, one with `mobility-history`; the full reply is 15 MB — never poll it), `show-bgp-neighbor.xml`
(2 of 13 iBGP full-mesh peers), `show-evpn-instance-designated-forwarder.xml`, `show-evpn-instance.xml`
(brief), `show-vlans.xml` (3 VLANs, 3 members each), plus the empty/none cases
`show-evpn-ip-prefix-database-empty.xml`, `show-evpn-mac-ip-table-empty.xml`, `show-system-license-none.xml`.
VTEP loopbacks → 192.0.2.N, host IPs → 203.0.113.N, MACs → 02:00:00:00:NN:NN, type-1 ESIs →
00:11:22:33:44:55:00:00:NN:00, ASN → 65000, peer descriptions → LEAF-NN. Element structure is original.

Real replacements for the synthetic Tier 2 samples (2026-09-18, pasted by the owner from a Junos 22.2 MPLS router,
addresses replaced): `show-ldp-neighbor.xml` (2 targeted + 2 link neighbours kept), `show-ldp-session.xml`,
`show-vrrp-summary.xml` (6 of 44 groups kept, including a **dual-stack interface that yields two rows with the same
interface/group** and multiple `virtual-ip-address` elements), `show-validation-session.xml`,
`show-validation-statistics.xml`. `show-mac-vrf-routing-instance-neighbor-info.xml` is from the EX4650 (23.4R2-S7.4, 3 of
14 neighbours kept), the `-mx.xml` variant from an MX204 L3 gateway (23.4R2-S3.9, mac-vrf instance name, 3 of 13 kept).

MX204 L3 gateway variants (2026-09-18, owner-supplied, 23.4R2-S3.9): `show-evpn-instance-extensive-mx.xml`
(mac-vrf instance, 3 of 53 anycast IRBs / bridge domains with `irb-interface` + `mode`, 3 of 13 neighbours, 3 remote
type-1 ESIs and 3 **type-5 gateway ESIs** whose local interface is `irb.N` with an empty status) and
`show-bgp-summary-mx.xml` (iBGP overlay peers, an eBGP EVPN peer to the other site's gateway without description,
and Internet/IX peers that carry no evpn RIB). Documentation ASNs 64496–64500 and 65000.

Duplicate-MAC positive sample (2026-09-22, owner-supplied, EX4650 Virtual Chassis on 23.4R2-S8.7; plan G3):
`show-evpn-database-state-duplicate.xml` holds one suppressed entry, a VRRP virtual MAC (`00:00:5e:00:01:96`) in
the L3 VNI of `mgmt-vrf` whose `active-source` is an ESI; the element shape equals the normal `show evpn database`,
so the shipped `count(mac-entry)` sensors read `dup-mac-instance[mgmt-vrf] = 1` and `dup-mac-total = 1`.
`show-evpn-database-mac-address-extensive.xml` is the per-MAC `show evpn database mac-address X extensive` taken
ten minutes earlier: the same MAC on the *peer* ESI-LAG (`source-local-origin ae5.0`, `source-mobility-seq-num 1`,
one `mobility-history/mobility-event` of type `local`); no shipped command polls it, it pins the element names
for the mobility work. What the sample does not give is the second source: `state duplicate` carries only the
current `active-source`. ESIs → `00:11:22:33:44:55:00:00:NN:00`, VTEP → `192.0.2.N`.

Templated-fabric samples (2026-09-24, one leaf of a **production** 12-leaf EVPN core, Junos 23.4R2-S3.9;
plan §10 X1/X10): `show-mac-vrf-forwarding-vxlan-tunnel-end-point-{source,remote}-templated.xml` and
`show-evpn-instance-extensive-templated.xml`. Nothing is shortened — the point of this capture is its
*shape*: **285 VNIs instantiated** from a fleet-wide VLAN template, of which only **12 bridge domains
have an up interface**, and every peer's flood list is exactly as long as its
`evpn-num-inclusive-multicast-routes` (2, 12, 15, 34, 40, 57, 57, 108, 112, 285×4). That equality is
the evidence that a flood list holds what a peer *advertises*, not what it has configured, which is
what `vni-flood-gap` got wrong. Anonymised bijectively per token, so the relationships survive:
VTEPs → 192.0.2.x (the two 198.51.100.x peers are the MX204 edge routers, not NETCONF-polled at the
time), ESIs and MACs renumbered, VNI/VLAN numbers and Junos versions original.
`TemplatedVniFabricTest` mirrors this leaf's view onto all twelve and asserts both directions:
zero issues here, and the 21,395 criticals the old rule produced.
