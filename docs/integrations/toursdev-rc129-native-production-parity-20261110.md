# Tours.dev rc.129 — Governance gate regression (2026-11-10)

Scope: GitHub PR #787 review branch only. No WordPress runtime deployment, grant, Breakglass or Production mutation.

## Findings and changes

1. Global Production remote/NHI mutation gating was also blocking native WordPress administrator actions. Native Policy now distinguishes a core-validated WP cookie or exact WP-CLI --user, with Site Profile enrollment, Write, Schema, admin capability and explicit global Kill Switch still required. Remote OAuth/App Password/independent agent identity cannot borrow this route.
2. Execution Commit Guard separately inspected the old global remote gate and rejected the native user even after Policy passed. Capture, revalidate and kill-switch material now check the same actor-aware decision; original remote authority still fails closed.
3. Two inherited static contracts required stale authorizing text. Approval Planner is bound to mad4b-write (not mad4b-admin) and Raw SQL uses the separately governed database Breakglass flag. Assertions were updated to current behavior without changing the underlying authority code.
4. An earlier review tried to replace the hardcoded deployment Adapter 0.7.0 with another hardcoded version 0.7.1. This was a regression of the October 2026 capability-first decision. The deployment handoff and runtime policy now contain **no required installed Adapter version** and no enumerated transition allowlist. Runtime Release Set reads actual official Adapter identity and required interfaces/WordPress Abilities. Known and future Adapter versions are accepted for normal runtime discovery on the basis of capabilities; the installed version is diagnostic only. A deliberate Adapter package update still requires the exact immutable certified release-set component version, manifest SHA, pair certification and rollback/readback. A package identity check is not a global installed-version gate. README version rc.107 was corrected to rc.129.
5. Reusable source contained fixed Tours.dev hostname gates. Central-only WPML structural CWA retains its narrowly reviewed Staging scope but binds enrolled Site Profile and local tours-central role. Fleet Intake catalog uses the same central role. Read-only WP-CLI plugin/theme inventory now uses the exact enrolled Site Profile on Staging or Production, without exposing arbitrary command text.
6. Per-file third-party provider hosts and 12 explicit tourism-module post-type identifiers are now reviewed in the non-authorizing Site Neutrality inventory. An unknown host, tenant domain or new business identifier still fails the checker. A domain module inventory does not authorize publication or Production deployment.

## Verified proof

- Fifteen targeted Governance, Self Update, Runtime, Provider Certification, Production Readiness and Site Neutrality contract suites PASS.
- Hermetic PHP actor and commit guard checks: 18 CLI, 7 WordPress session, 4 global Kill Switch checks PASS; zero site writes.
- Site Neutrality scanned 814 source files and confirmed unrelated retail, education and services test fixtures are not treated as an Egypt tourism site.
- GitHub CI has a permanent native Production parity test step on PHP 8.1, 8.3 and 8.5. Full GitHub Actions/Release Verdict is not yet observed and is not implied by local success.
- Native WordPress administrative writes do not require an extra OAuth certificate. MCP/remote autonomous agents still require their own identity, grants and original Production gate. Breakglass/SQL, Developer, shell and release operations are not included.

## Remaining original release steps

Keep PR #787 Draft until current-head CI checks and a fresh owner attestation are verified. The prior owner attestation is bound to head 31af0e3280883d77954caafbc659705a625ccd63 only. After review, produce a newly signed immutable release from the actual GitHub merged source commit; then run Staging Runtime Release Set, exact Grants, Brand Core and Merit Publish readiness. Merit #69449 remains Draft with Price Pending supplier evidence and no commerce authority.

## Permanent no-fixed-version MCP Adapter regression

- Static contracts forbid returning `target_adapter_version`, `supported_transition_adapter_versions` or `required_version` as global installed runtime requirements.
- Real PHP fixture `capability-first-mcp-adapter-gate-runtime.php` passes versions `0.6.1`, `0.7.0`, `0.7.1`, future version `0.8.0`, a much newer version, an unrecognized version and an empty diagnostic version when the necessary runtime capabilities/official origin/Abilities are present. It fails closed on missing plugin, ambiguous identity, missing runtime, foreign class source, absent WordPress Abilities and unsupported network activation. A release component without a certified artifact never becomes an implicit update.
- `mcp-protocol-profiles.json` can keep an audited historical certified Adapter as a **protocol-test reference**. This is not the required installed version. The live protocol negotiator independently checks runtime-supported revisions and rejects unsupported features.
- CI now runs the hermetic runtime tests and version-neutral static contracts on PHP 8.1, 8.3 and 8.5 as part of the existing Site Control Plane workflow.
