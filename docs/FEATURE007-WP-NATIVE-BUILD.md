# WordPress-native source worker — Feature 007 (Staging only)

The feature introduces an optional **PHP/WordPress build worker**, separate from
the existing external `standalone_source_build` runner. It is not enabled as
the default release channel, does not execute downloaded source and does not
authorize deployment.

## Owner-delegated self-certification on Staging

**New optional trust policy:** \`site_self_certified_staging\`. The enrolled
Staging WordPress site is explicitly allowed to **accept its own local build
checks as the evidence basis for a Staging install**. This eliminates the
external Ed25519 signer and PHP 7.4/8.3 matrix *for this locally self-approved
Staging lane*, but does not claim those tests ran or create a certificate for
CI, master, Production or the conventional signed-candidate lane.

The following are distinct, dynamically enrolled WordPress Abilities:

- \`mad4b/wp-native-build-start\`: owner-approved exact-source ZIP packaging.
- \`mad4b/wp-native-build-simulate\`: read-only package checks.
- \`mad4b/wp-native-self-approve-plan\`: read-only, exact build/receipt
  and existing governed WordPress updater preflight.
- \`mad4b/wp-native-self-approve-install\`: **mutating** Staging-only action
  with existing enrolled-owner permissions, OAuth authority step-up,
  the original governed candidate upload grant and one-time approval, exact
  plan SHA, explicit \`INSTALL SELF APPROVED PACKAGE ON STAGING\` confirmation
  and an append-only audit of the accepted trust downgrade.

### Example MCP plan input

\`\`\`json
{
  "expected_head": "<40-character immutable GitHub commit SHA>",
  "reason": "Owner delegates self-certification for this Staging-only install"
}
\`\`\`

Review the returned \`eligible\`, \`plan_sha256\`,
\`evidence_mode=site_self_certified_staging\` and the explicit
\`independent_signature_present=false\`,
\`native_php_matrix_executed=false\`, \`release_certified=false\`.
Only a locally completed \`BUILT_UNVERIFIED\` artifact with matching private
SHA-256 ZIP bytes, canonical receipt, token-screening count, Staging Site
Profile and exact repository commit can become eligible. A source commit
or passing simulated fixture alone is insufficient.

For apply, send the same \`expected_head\` and \`reason\`, reviewed
\`expected_plan_sha256\` and literal
\`confirmation="INSTALL SELF APPROVED PACKAGE ON STAGING"\`, plus the
governance-controlled approval ticket as required by the existing upload
authority. The code copies the already-built private package into the
**existing** \`MAD4B_SCP_Self_Update::upload_apply\` path. That path retains
source re-resolution, archive/provenance verification, backup, maintenance
lease, post-update readback and rollback. No new unsafe installer is created.

**This is an explicit owner-accepted reduction in test independence, not a
security certification.** Production rejects the action. GitHub Release
verdicts, main/master branch protection and conventional signed release gates
are not changed. The option to self-certify does NOT implicitly grant the
governed upload permission or approve its one-time operation. New source
Abilities become available on the live site only after an independently
authorized bootstrap installs a package containing them.

Source fixtures:

\`\`\`sh
php wp-content/plugins/mad4b-site-control-plane/tests/wp-native-build-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/wp-native-self-approval-runtime.php
\`\`\`

These commands are test instructions and not claims that native PHP 7.4/8.3
or Staging execution has already passed.

## WordPress-local acceptance simulation (non-authorizing)

On Staging, the read-only `mad4b/wp-native-build-simulate` Ability provides a
**local self-check** for an immutable `expected_head` after a governed native
build reaches `BUILT_UNVERIFIED`. It reads the persisted build job and private
workspace; it never changes WordPress data, installs the ZIP, executes downloaded
PHP, generates a signer key, or signs a release receipt.

It verifies only local observations:

- Job/source SHA is an exact match to the current Staging Site Profile.
- ZIP is a real private file within the bounded build size, and its actual
  SHA-256 and byte length match the persisted job.
- Private canonical receipt SHA-256 and exact source/package identity agree
  with the observed ZIP and job.
- The builder recorded PHP token screening for **every** `.php` source entry
  (screening is not a PHP CLI runtime test).

Example governed Read input:

```json
{"ability_name":"mad4b/wp-native-build-simulate","input":{"expected_head":"<40-character-immutable-SHA>"}}
```

The response is either `LOCAL_SELF_CHECKS_PASSED_UNTRUSTED` or
`LOCAL_SELF_CHECKS_BLOCKED`. **Neither is a release PASS.** The required
external G9 test, PHP 7.4 and PHP 8.3 native syntax matrix, isolated ZIP runtime
integrity, independently verified source and Ed25519 signer all remain separate
and explicitly `NOT_EXECUTED_BY_SIMULATOR` or
`NOT_INDEPENDENTLY_VERIFIED`. The output always sets
`release_certified=false`, `independent_signature_present=false`,
`mutation_performed=false`, and `production_authorized=false`.

This is useful to diagnose corrupted or stale files **entirely within
WordPress** before paying for an independent release run. It cannot generate
trusted evidence required by `control-plane-selected-head-plan`. A site that
creates and signs its own release certificate cannot be its own independent
attestor. Do not add `LOCAL_SELF_CHECKS_PASSED_UNTRUSTED` as an accepted
Staging release evidence mode or weaken any existing publication/approval gate.

Acceptance fixtures include rejected non-SHA inputs, tampered ZIP refusal,
Production refusal, and a locally intact ZIP which remains untrusted.

## Adoption decision and release gates

WordPress-native build is the **preferred opt-in source packaging route for eligible
Staging sites**. It is not the default automatic updater. The existing
`mad4b/standalone-build-plan` discovers capability and selects the native route
for **build-only** when all checks pass. Full `local-checks`, independent
verification, signing and final distribution retain the separately trusted
runner/attestor route. The fallback remains the external standalone builder.

This is a source-level adoption policy, **not** evidence that a new plugin is
installed, that real Staging cron executed, or that release certification passed.
The evidence gates are: exact Staging identity; owner opt-in; PHP/native I/O
readiness; pinned Git object verification; deterministic source package and
canonical JSON/ZIP parity; independent native tests; separately signed CI-outage
attestation; immutable release publication; governed staging install/readback/
rollback. Each gate must be observed, never inferred from a queued CI job. The WP
build status exposes the canonical receipt and digest, not approval. A
separate attestor still validates real package bytes, native tests and
independent signer key provenance.

## Abilities

- `mad4b/wp-native-build-capabilities`: read-only resource and security checks
- `mad4b/wp-native-build-plan`: exact commit and Site Profile-bound plan
- `mad4b/wp-native-build-start`: owner / OAuth step-up / central approval; opt-in
- `mad4b/wp-native-build-status`: source download and build status
- `mad4b/wp-native-build-export-chunk`: enrolled-owner-only, exact SHA and
  bounded 8 KiB chunks; caller recomputes final archive SHA-256

## Execution

1. Enrolled Staging owner enables existing **WordPress-native candidate mode**.
2. Discover capability, plan one immutable SHA, approve the exact start.
3. WP-Cron fetches GitHub commit/tree objects from the pinned
   `mad4bdigital-ai/WordPress` repository and the allowed
   `wp-content/plugins/mad4b-site-control-plane` subtree.
4. Fetch source blobs in bounded batches. Verify SHA-1 Git object identity,
   declared sizes, paths and absence of symlinks/submodules.
5. Store in a private workspace **outside webroot**. Re-check the Site Profile,
   lease deadline and files during packaging. Parse PHP tokens without running
   fetched code.
6. Emit a deterministic ZIP with stored entries and embedded provenance,
   plus `CANONICAL-PACKAGE-RECEIPT.json` matching the canonical receipt
   fields and hashes. Check every Git blob again while writing the archive,
   calculate SHA-256 and refuse over-budget archives.
7. Mark **BUILT_UNVERIFIED**. Export remains owner-only. No install, GitHub
   publication, signature or release acceptance occurs at this stage.

Only the current trusted plugin's PHP runs; the new repository PHP files
remain data until independent review, certification and governed installation.

## Explicit restrictions

- Staging only, single-site and opt-in. Production is always denied.
- PHP `pack`/`crc32`, a PHP token parser and a private writable temporary directory are required. The canonical ZIP_STORED writer does not need ZipArchive.
  When unavailable, `capabilities` reports blockers; do not enable `exec`,
  `shell_exec`, arbitrary PHP eval or raw SQL as workarounds.
- Requires outbound GitHub API HTTPS and functional WP-Cron. Network issues,
  host rate limits, truncated trees and unsafe files fail closed.
- `token_get_all(TOKEN_PARSE)` is only a preliminary PHP syntax screen; it
  is **not** native PHP CLI lint, DB integration, browser testing, or rollback.
- The PHP writer explicitly reproduces the canonical Python ZIP_STORED header,
  ordering, file mode and timestamps. Golden-vector checks cover SHA-256 parity;
  the **real exact-HEAD archive** must still be independently compared before
  attestation. A trusted process executes native tests, verifies the build
  receipt and signs source-bound CI-outage evidence before publication.
- The existing `mad4b/control-plane-selected-head-plan/apply` still requires
  a certified manifest, backup, approval, readback and rollback. The new
  worker does not weaken any existing gate.
- WP-Cron depends on traffic or a configured cron trigger. Operators can
  check status; it is not proof of background completion.

## Test

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-wp-native-build.php
php wp-content/plugins/mad4b-site-control-plane/tests/wp-native-build-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/standalone-build-control-runtime.php
```

Acceptance for any exact PR head additionally requires live Staging run,
canonical archive comparison, external attestation, install/readback and
rollback evidence. **Source delivery alone is not runtime certification.**


## G9 workflow fingerprint drift — mandatory paired updates

The G9 verifier intentionally locks `.github/workflows/feature-007-spec-ci.yml`
to exact SHA-256 and byte length recorded in `g9-delivery.json`. Any later
workflow edit without the corresponding evidence change fails before PHP lint.

When editing the workflow, use the **one-commit** guarded local command rather
than publishing the workflow first and repinning afterward:

```powershell
$Sha = (Get-FileHash ".github/workflows/feature-007-spec-ci.yml" -Algorithm SHA256).Hash.ToLowerInvariant()
python tools/mad4b_g9_integrity_repin.py --apply --commit-pair --reviewed-workflow-sha256 $Sha
```

Run from the feature repository root with the changed workflow still **uncommitted**
and with no other staged changes. The script requires source-owner SHA review,
checks every G9 evidence fingerprint, validates the workflow security contract,
runs the full G9 delivery validator, and commits only the workflow and G9
manifest together. It refuses other fingerprint drift, unknown branches,
Production/main/master/release branches, unreviewed changes and dirty index.
This does **not** claim CI, Staging acceptance, or release approval.

For already committed workflow edits, the older `--apply` (without
`--commit-pair`) remains a reviewed repair route:

```powershell
$Sha = (Get-FileHash ".github/workflows/feature-007-spec-ci.yml" -Algorithm SHA256).Hash.ToLowerInvariant()
python tools/mad4b_g9_integrity_repin.py --apply --reviewed-workflow-sha256 $Sha
git diff --check
git add specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g9-delivery.json
git commit -m "fix(feature007): repin reviewed G9 workflow evidence"
```

That recovery is intentionally two commits and only for existing drift.
**New workflow updates must use the paired path.** No build kit should
silently modify pinned source or claim to certify a dirty checkout.


## Dynamic governed Ability mount — no central name hardcoding

The native build start operation is enrolled through the *existing*
`MAD4B_SCP_Adapter_Registry` extension hook:

1. An approved module's `boot()` subscribes to
   `mad4b_scp_register_adapters` before `wp_abilities_api_init`.
2. Its trusted `MAD4B_SCP_Adapter_Base` subclass declares an
   `ability_names()` map of read/content/admin/write and a provider identity.
3. `MAD4B_SCP_Servers` already discovers adapter-declared Admin names for the
   Admin server, adapter-declared mutations for the governed Write server, and
   the descriptor's provider for ChatGPT capability inspection.
4. A runtime eligibility method rechecks Staging opt-in and resource
   prerequisites before the mutation is mounted; actual execution still
   requires site-bound write authority, enrolled owner OAuth Step-Up, exact
   one-time approval, the original permission callback, and a runtime
   source-bound plan. The adapter **never replaces** the original callback.
5. A missing/uncertified provider is not auto-elevated. Production is excluded
   by the native builder. Untrusted arbitrary WordPress Ability metadata is
   insufficient to gain a mounted mutation.

There is **no native-build ability name in the fixed `Servers` allowlists**.
Adding another first-party operation requires implementing an adapter and its
governance tests, but no central allowlist modification. General discovery is
not permission delegation or an authorization bypass.

The regression fixture `tests/wp-native-build-runtime.php` asserts
idempotent adapter enrollment, Admin-only declaration, Staging admission,
Production rejection, and absence of a static build name in Servers. The
package workflow also PHP-lints the new adapter.

Operational deployment remains separately gated: this source change does
not update the installed Staging package until a certified exact-HEAD release
is installed, read back, and independently accepted.
