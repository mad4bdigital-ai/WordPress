# PR #814 — Local OAuth CI: Dynamic Certified MCP Adapter Profile

**Status: source-only workflow fix, no runtime admission and no site install.**

The Local OAuth integration matrix tested `target-0.7.0` but read a retired
`runtime-release-policy.json` field, `target_adapter_version`, which is no
longer part of the active contract. The current policy delegates exact
Adapter target identity to `certified_runtime_release_set.mcp_adapter`.
The retired lookup failed with `KeyError` *before* the existing verified
archive download / exact SHA and critical file readback stages.

The new standalone standard-library resolver selects the test version from
its declared `target-<semver>` matrix label, **not** a fallback environment
or WordPress option; validates the active release policy pointer; requires
a matching first-party certified provider profile; and fails closed for
missing or malformed archive SHA256, archive size, HTTPS package URL or the
exact `vendor/autoload_packages.php` critical-file SHA256. Shell values are
quoted rather than executable source strings. The GitHub workflow retains
its package length/hash checks and independently verifies the unpacked
critical file before WordPress activation. This does not issue release
signatures, update certified runtime authority or authorize any Production
write.

All **5/5** focused unit tests passed, including currently certified
`0.7.0` and `0.7.1`, injection-like target strings, missing versions,
changed policy source, bad archive/critical hashes, malformed sizes and HTTP
package URLs. Full-root Python suite ran **304 tests**, with **0 new
assertion failures** and 14 historical signed Original313 postimage errors.
Control Plane historical source contract remains 14/14 passed.

Separate remaining Local OAuth failures (reviewed direct step-up
`mad4b/operator-grants-converge` not appearing in the authenticated
pre-request catalog) are **not** resolved or suppressed by this workflow
version-selection patch. CI needs to prove correct dynamic Server projection
and bearer-scoped authorization on disposable WordPress, independently.
The PR remains Draft / release HOLD pending exact-head owner approval and
Original313 signed runtime postimage evidence.
