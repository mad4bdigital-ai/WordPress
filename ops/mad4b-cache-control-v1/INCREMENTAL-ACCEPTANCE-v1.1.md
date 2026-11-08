# MAD4B WordPress Cache Control v1.1 — incremental acceptance

Updated on Tours.dev Staging and Wayfatours Production, November 8 2026.

## Live changes

- Public permalink validation now rejects invalid and double-encoded paths,
  traversal, private WooCommerce, alternate ports and scheme changes.
  Valid percent-encoded Arabic slugs are accepted.
- Admin confirmation delegates preview ownership/TTL and revalidated
  fingerprint checks to pure, independently tested methods.
  One-use transient consumption remains ahead of all socket I/O.
- Transient storage failure fails closed with an admin error.
- No Redis FLUSHDB or full Nginx cache directory clear is added.
- Nginx FastCGI TTL and private bypass rules are untouched.

## Validation

- PHP lint on both live containers PASS.
- Path tests: 5 accepted, 18 rejected private/unsafe, 5 direct
  socket invalid-path refusals on each site.
- Pure preview tests: 1 accepted, 7 rejected contexts and
  5 fingerprint mismatch cases on each site.
- Real homepages HTTP 200, FastCGI sockets active.
- Earlier admin preview and disposable socket QA remain documented.
- Actual WordPress admin POST APPLY was previously BLOCKED by the
  safety gate; it was not rerun or approved. Pure tests do not replace it.

## Passive readiness monitor

A 30-minute read-only monitor checks approved plugin SHA on both
companies, active systemd socket status and Registry v2 file presence.
It never invokes WP-CLI or connects to the FastCGI socket.
Unknown source drift alerts, but is not overwritten.

Readback: Tours.dev PASS; Wayfatours DEGRADED_INHERITANCE
(Registry v2 absent, local fallback); DRIFT 0.
General cache coherence DEGRADED is separate: Tours.dev Redis warming
2/12 due to the existing optional WP-CLI admission limit.

A read-only Wayfatours WP-CLI shadow of Registry v2 returned no stored
rules (revision 0) and equal Page Dependency and Pricing Cache resource
decisions in one evaluation. It did NOT prove full Production pricing,
rendering or booking parity. Registry v2 was not installed.

## Open gates

- Approved end-to-end admin confirmation and receipt.
- Full Registry v2 inheritance on Wayfatours requires separate parity.
- New-company provisioning is not enrolled for this module.
- GitHub CI status must be checked independently.

Company observer policy and absolute tenant paths remain VPS-local.
Report directory:
/opt/mad4b-company-provisioning/reports/cache-control-admin-20261108/
