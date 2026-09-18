Anonymised `| display xml` samples collected 2026-09-18 from Junos 23.4R2-S3 (EX4650 EVPN-VXLAN leaf)
and 24.4R2-S3 (SRX chassis cluster). Hostnames, IPs, ASN and MACs replaced with documentation values;
structure and counters are original. Peer lists were shortened. Intended as Pest fixtures for
saffer-it/librenms-netconf (see ../../NETCONF_PLUGIN_PLAN.md). EVPN commands added 2026-09-18 (instance list, ESIs, neighbours and bridge domains shortened).

2026-09-18, Phase 1 live test on the same EX4650 (now 23.4R2-S7.4, single-member Virtual Chassis):
`show-version-multi-re.xml` (multi-routing-engine-results wrapper, package list shortened),
`show-system-alarms.xml` (four license alarms), `show-chassis-alarms.xml` (`no-active-alarms`),
`show-virtual-chassis-status.xml`. Payload size reference: `show interfaces extensive` on this
74-physical-interface switch returned 982 kB in 3.2 s over the cli transport.

File names follow `FixtureReplay::slug(command)` (`show route summary` → `show-route-summary.xml`) so the
directory can be replayed with `lnms netconf:validate --replay=tests/fixtures/junos`; variants carry a
suffix (`show-chassis-cluster-status-not-enabled.xml`). The duplicate-MAC, L3-context and chassis-alarm
samples are the empty/none cases.

Tier 2 samples (2026-09-18): `show-ntp-status.xml`, `show-ntp-associations.xml` (peer renamed),
`show-krt-queue.xml`, `show-system-license-usage.xml`, `show-system-commit.xml` (four entries, user
renamed), `show-system-uptime.xml` (multi-RE), `show-lacp-interfaces.xml` (two aggregates) are
captures from the EX4650. `show-ldp-neighbor.xml`, `show-ldp-session.xml`, `show-validation-session.xml`,
`show-validation-statistics.xml` and `show-vrrp-summary.xml` are **synthetic**, built from the element
names in junos_exporter's `pkg/features/{ldp,rpki,vrrp}/rpc.go` (MIT) — the leaf runs none of these
protocols; the `-not-running.xml` variants are its real `<output>` / `<xnm:warning>` replies.
