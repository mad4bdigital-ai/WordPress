# MAD4B Host Authority — v125 scoped runtime dependency reconciliation

**Base:** master `1ed359a52ecd1c94574ddfd31c9327de5779b856`.
**Exact source:** `ba644d24aa5c0a0750e35cc61b5e558a5a6a6401`.
**Company/environment/host:** All Royal Egypt / Staging / hosting-2.

## What has been reconciled

Starting from five original WordPress runtime classes (Original305, Original306,
Original313, Original318 and Original328), follow exact PHP `MAD4B_Class::`
references recursively through the source `mad4b-platform` tree.

**Result:** 46 reachable referenced symbols, 32 canonical PHP source files,
and 13 references to classes *outside* the platform. All 32 files are copied
byte-for-byte into an **inert review path** under
`deploy/host-authority/reconciliation/v125/wordpress-platform/` with both
SHA-256 and Git Blob SHA identity records. There are no new `wp-content/`
or `.github/workflows/` changes and **nothing is installed**.

Source `ordered-mu-map.php` recognizes 27 selected files; another five
requires/imports are not directly present in that ordered map. Module parsing
is not proof of correct bootstrap initialization.

## Critical collision: Original305 sealed commands

The named class `MAD4B_Original_WPCLI_Sealed_Command_V2` is declared in
**two different PHP files** on the source branch, and the body differs:

- The ordered map points to
  `modules/compat/305-mad4b-original-sealed-rebind.php`.
- The alternate `modules/compat/305-mad4b-original-wpcli-seal-v2.php`
  is not ordered for loading. Its digest is preserved in the manifest but its
  bytes are **not bundled**. Do not load both files.

This choice follows the source's ordered loader; it is **not** a new authority
policy or permission to replace the live Original305 implementation. An
independent source and code owner review must confirm which implementation is
canonical before an executable runtime release.

## Remaining blocking dependencies

The 13 external symbols are recorded in the manifest. The present master has
two relevant class definitions (`MAD4B_SCP_Audit` and
`MAD4B_SCP_Site_Profile`), but their load order, code identity and
authorization interactions are unverified. One original transport class is in
the source's `mad4b-commerce` plugin, not bundled. Other references must
be checked against their actual enrolled services, current active MU files
and the controlled Central WordPress bootstrap.

This file set contains 27 `add_action` tokens and 34
`register_rest_route` tokens. These hooks and routes are **not tested as
runtime behaviors**. Do not assume class-reference closure equals a
complete executing plugin. A full dynamic include graph, module loader,
Composer/WordPress initialization and permissions audit is still required.

## Non-executing tests and gates

`python3 -B tests/host-v125/test_runtime_dependency_closure.py`

The tests validate exact content and Git blob hashes; duplicated class
exclusion, known unresolved dependencies and restrictive activation flags;
complete root symbol reachability; and negative tampering controls. All
selected PHP files must pass `php -l` in isolated PHP 8.5 Docker,
**without including or executing them**.

This PR does **not**:
- issue or renew Owner Requests;
- create or change an Original313 signed Enrollment / Revision / Receipt;
- install Original306 workers, WordPress plugins or systemd timers;
- grant Original318 Host authority;
- deploy to Staging or Production;
- prove Production parity.

A **separate** executable release is required after resolving loader order,
all external references, Staging-specific Original328 identity, original
signed Owner attribution, REST/AJAX authorization, signed issuance idempotency,
rollback, and master CI + an exact-head owner attestation. Current live Staging
Anchor Revision 1 remains immutable and operational.

## Actual Tours.dev Staging loaded-class evidence

The read-only WordPress CLI inspection used \`class_exists($name,false)\`
(no autoload) plus ReflectionClass file/source SHA readback, with no writes.
Evidence is in \`governance/host-authority/live-staging-runtime-evidence-v125.json\`.

- **5/5** Original305, Original306, Original313, Original318 and Original328
  candidate file hashes match the classes actually loaded on Staging.
- All **13/13** referenced external classes are loaded on Staging, but
  **11/13** external class files are absent from current master at the live
  locations. Of the two found in master, \`MAD4B_SCP_Audit\` matches live
  source; \`MAD4B_SCP_Site_Profile\` differs.
- The Portfolio Manager and some MU/Commerce sources are not present as
  equivalent master files. A staging-loaded class does not prove it can
  initialize or authorize requests when imported into current master.
- The live Original305 sealed command class comes from the ordered-map
  \`305-mad4b-original-sealed-rebind.php\` implementation; the competing
  definition is not loaded.

**No runtime activation is authorized** by this evidence. A separate
master-vs-Staging identity-preserving source reconciliation is mandatory.


The live \`get_included_files()\` readback confirms that all **32/32**
candidate modules are currently included in Tours.dev Staging, including
the five modules absent from the primary ordered map. The competing
Original305 sealed-command definition is **not** included. This confirms
source path selection on the *current live Staging installation*, but does
not establish transitive bootstrap availability, authorization or safety
when reconstituted from the incomplete master repository.

## Source reconciliation of live dependent plugins (Staging only)

The actual live MU bootstrap was opened and inspected. It loads a **379-entry**
integrity-checked manifest (320 platform modules and 59 commerce modules),
not 377 entries as an older note said. This manifest is independently
verified by \`MAD4B_MU_Map_Contract_V1::verify()\` against its SHA-256,
module names, source paths and family counts.

A **source-only snapshot** of the 64 Portfolio Manager PHP files actually
included by WP-CLI plus six supplementary PHP module/loader/profile sources
and the exact MU map JSON integrity contract (**71 source/contract files**)
is preserved under \`deploy/host-authority/reconciliation/v125/live-dependency-sources/\`.
The snapshot contains **no WordPress autoload changes** and no secret
signing key or private Host artifact. The original byte hashes are recorded
in \`governance/host-authority/live-dependency-source-parity-v125.json\`.

Of 71 preserved files, 70 are not present at the same path in current
master; the one that exists has a different hash. No active master source
is overwritten. The SCP live Site Profile and Skill Set are kept as
alternative evidence; blindly replacing the master Site Profile would
discard a conflicting newer/older evolution without proof.

### Permission baseline, not yet an executable acceptance certificate

The original live Staging Portfolio Manager was tested as anonymous
\`user_id=0\` without executing any write. Original
\`admin_permission()\` rejects with \`mad4b_manager_admin_required\`;
\`read_permission()\` rejects with \`mad4b_manager_login_required\`.
The WordPress route registry showed 13 company/host/owner routes.
This verifies a **negative baseline**, not full REST/AJAX role/nonce/
OAuth coverage. A new executable release must reproduce these denials
and validate the signed Original318/312/313 contracts independently.

A full authenticated admin/runtime cycle, front-end anonymous fast-path
and site-specific Production parity are still outstanding. **Do not
deploy this inert snapshot as a complete plugin** or run either its
Portfolio Manager main file or MU runtime router as a standalone script.
