# Adaptive Runtime Convergence — rc.95 operational recovery

Scope: recovery above master `b43a90bab987c609792c8e410d57080b3dc4a2ae` after PR #254 merged. This extends the rc.94 runtime implementation; it does not certify unobserved operations or close documentation-only tasks.

## Operational recovery in rc.95

- Search Intelligence exposes provider enrollment on its canonical page and Providers section even without a Search Profile. Optional adapter metadata supplies the credential fields; the UI does not select vendors or accept arbitrary endpoints. Local administrator POST actions require an exact provider nonce, enrolled non-Production configuration authority and a CAS revision. Credentials and observed account receipts use AES-256-GCM encryption bound to the site, provider and record revision, with opaque rotating handles. Secrets, handles, account email and raw account responses do not appear in HTML, public Search status or audit events.
- Saving credentials performs no outbound request and preserves blank fields only when all fields are blank; complete replacement invalidates the old handle and account evidence. Explicit connection testing calls only the adapter's pinned account endpoint. SerpApi reports its native remaining/monthly allowance and renewal date when supplied; a date without a timezone or time is not promoted to an exact budget reset instant. DataForSEO reports USD balance without inventing a search quota. Failure invalidates an old account observation; a concurrent credential change fences the stale response. Read-only page/status rendering performs no network requests. Account verification does not mint behavioral certification, evidence rights, cost authority, write grants or budget admission; paid capture remains under the existing certified provider, profile, shared-account and hierarchical budget boundaries.

- All submenu boot callbacks use a central scheduler after the parent menu. This prevents WordPress from deriving a different page hook for Content Pipeline or future pages loaded earlier than the parent. Route capabilities, canonical URLs, alias security and WordPress authentication remain authoritative.
- Settings refresh now verifies a typed form identity and persisted values. Brand Profile, source policy and review policy supply matching server-rendered and AJAX view proofs; no Site Profile CAS fields are assumed for unrelated forms. Ambiguous, missing, mistyped or stale views cannot report success or repeat the mutation blindly. Administrator and nonce admission remain required.
- Primary Google connection uses the selected Managed, Dedicated or Custom method. Configuration of a different method no longer hides the active method's setup needs or switches its primary action. Public unsupported Google scope identifiers are exposed in a bounded, signed, site/method/grant-bound diagnostic from a validated OAuth exchange. Arbitrary provider strings are hashed; rejected tokens and credential material are never stored in diagnostics. A successful connection clears stale rejection evidence. Google consent itself remains external.
- Context write readiness renders the candidate-bound checkpoint using defined variables. Connection readiness separates the policy gate, candidate checkpoint and deferred exact grant verdict. Cheap status cannot fabricate execution authority or scan grants on protocol/admin hot paths.
- Import/Export component versions resolve the provider contract's exact main plugin file. Add-ons cannot impersonate or overwrite a core component, catalog order is irrelevant, and duplicate identities are unmeasured. This is independent of patch-version certification.
- Periodic Staging observations may refresh attribution from an unexpired sealed baseline only after rechecking the complete site, profile, actor, package binding, grants, schemas/risk and transport. Missing/expired/tampered evidence cannot be revived by Cron. Native update permits now also pin write contracts before package replacement. Observation creates no grants, update intent or authority.
- Adaptive status defaults to a bounded provider summary with capability-state counts. Explicit provider details and stable, receipt-bound paging remain available. A changed receipt requires a fresh first page; pages cannot be merged across generations. The admin overview declares its bounded sample. Reads never execute the observer.
- Core convergence reports a real owner/external gate when safe phases are complete; a historical pending checkpoint cannot turn an external prerequisite into endless automatic work.
- Write-runtime and external MCP reducers distinguish an ungoverned direct schema leak from an existing reviewed hot-set projection. Only a current site-bound, schema/classification-pinned non-Breakglass mutation with verified Authorization and final Execution Fence wrappers and an available original execution lane supplies structural exposure evidence. Same catalog names with missing/stale/foreign proof remain rejected. This changes no grant, approval, subject or invocation permission and does not manufacture a client handshake.

Regressions are wired to CI: `admin-route-registry-runtime.php`, `admin-settings-persistence-runtime.mjs`, `context-admin-recovery-runtime.php`, `context-oauth-lifecycle-runtime.php`, `connection-catalog-readiness-runtime.php`, `wp-import-export-readonly-bootstrap-runtime.php`, `post-update-continuation-guards-runtime.php` and `adaptive-runtime-convergence-runtime.php`.

## Implemented behavior

- Brand Context scalar option revisions survive WordPress cold-cache string conversion. Structured records remain type-exact; missing option writes cannot be mistaken for empty/false values. Compensation and append-only audit failures remain fail-closed.
- Enrollment's read-only authority plan, binding audit and multi-authority status definitions are wired independently of the passive admin lifecycle. Endpoint diagnostics identify bounded failing capability names/error codes without disclosing exception messages.
- Every MAD4B admin page declares its route and required capability. Generic GET/HEAD legacy aliases redirect to WordPress's canonical admin dispatcher, including subdirectory installs. WordPress retains page authentication/permissions; unknown paths, POST/actions, nonce requests and unsafe query values are not replayed.
- WP All Import read-only discovery pins the loaded provider, its autoloader and five model declarations to bounded current local files. It fingerprints the actual artifact and accepts structurally discoverable updated packages without a static patch-version allowlist. Missing, escaped or foreign class definitions fail closed. Import/Export execution remains unmounted until its real execution/diff/rollback contract exists.
- Plugin update/activation events and a same-version replacement stamp schedule an enrolled-Staging observer. It evaluates at most three providers per slice under a fenced shared maintenance lease, then polls hourly. It persists a signed, non-authorizing registry of artifact identity, semantic contract diff, verified behavioral/rollback evidence, and capability-local states. Invalid/foreign assessment contracts and provider exceptions cannot poison their neighbors; malformed capability rows are isolated. Per-provider observation epochs keep earlier slices explicitly stale until rechecked, including hourly polls. Corrupted event metadata is recovered only by the worker. Reads never run the worker or persist readiness.
- The observer schedules existing safe core convergence for a new package, retaining blocked lifecycle checkpoints. Managed Skills/schema/MCP bootstrap continue to use the existing lifecycle services.
- A healthy already-approved exact Staging authority can produce a sealed, audited seven-day observation. A later manual package replacement may prepare a one-time continuation only after full installed-file verification and matching a root-trusted master release. Grants, schemas/annotations, profile, subject, transport and previous binding must remain unchanged. The existing transactional consume, actor-revocation, exact readback and replay guards still own execution. There is no grant creation or rollback into a broader scope.
- Provider health distinguishes artifact drift from observed contract failure, and exposes isolated capabilities alongside eligible reads/writes. It creates no global mutation permission.
- Elementor now participates in the capability-first catalog. Structurally compatible reads remain available across artifact drift; `elementor/update-widget-settings` may enter the existing one-time behavioral recertification runner only through an exact request-local probe context bound to provider, capability, target ability, artifact, contract and input digest. That context is cleared in `finally`. Structural Elementor writes remain high-risk gated and cannot use this bridge.
- Google scope rejection now presents an explicit external action. Unsupported scopes remain rejected and rejected tokens are not stored.

## Administrator workflow closure

All 14 registered pages and their tabs/sections are covered by the administrator
operability audit in `admin-operational-audit.md`. The repair adds first-profile
creation and selection, native pipeline stage selection, typed navigation across
all administrator surfaces, action feedback bound to the current actor/session
and persisted view, and visible maintenance worker/runtime transaction results.
The disposable WordPress CI smoke suite checks every page and all Search
sections, nonce/action wiring, direct-render permissions and zero-HTTP GET views.
It does not claim live browser acceptance or automatic provider certification.

## Unified All Royal Egypt report reconciliation

The October 6 rc.94 delivery handoff and the exhaustive ability audit are one evidence chain, not competing readiness reports. The live handoff proves the current deployed rc.94 core/authority baseline; the exhaustive audit identifies capability paths that were either repository defects or evidence/configuration prerequisites. PR #255 owns the repository-side closure for those findings.

Repository/runtime defects closed in this PR:
- `mad4b/approval-plan` is classified on the canonical governed-write bootstrap lane before the central Authorization wrapper seals the descriptor. The compact ChatGPT write dispatcher and reviewed schema-pinned hot-set remain transport/projection only; neither creates grants or approval.
- Write-runtime certification distinguishes an ungoverned direct write-schema leak from a reviewed fenced projection, while requiring the fixed `mad4b/write-discover`, `mad4b/write-info` and `mad4b/write-execute` transport on the same `mad4b-chatgpt` resource.
- OAuth issuer identity is canonicalized once by the Resource Bridge. Multi-Authority Registry and Production certification reuse the same authority id/type and the same domain-separated issuer fingerprint, preventing the live `issuer_not_bound_to_registry` false negative.
- Provider version drift is not treated as incompatibility. Capability-first recertification exposes automatic observation/structural reassessment but never automatic mutation probes, grants, mounts or high-risk promotion.
- Runtime adapters now emit a candidate capability graph independently of the reviewed policy catalog. New adapter abilities become visible as `UNCLASSIFIED_FAIL_CLOSED`; the repository catalog acts as the semantic risk/reversibility/certification overlay and cannot create authority. This reduces hardcoded discovery pressure without making policy dynamic or unreviewed.
- Reviewed isolated native-provider transports can materialize from the cataloged transport contract. JetEngine native reads and mutations now use the JetEngine provider identity; high-risk native schema writes remain behaviorally gated/fail-closed.
- WP Import/Export exposes an exact behavioral-acceptance handoff (composite artifact, disposable saved job, import dry-run diff/rollback, export artifact ingest and signed operation receipt). Execution remains unmounted until that real evidence exists.
- Search Profile observation state and provider-spend freeze state use dedicated revision-fenced controls with exact readback. Advanced JSON policy editing is explicitly unable to resume observations or unfreeze spend.
- All MAD4B administrator pages expose Effective environment and Raw WordPress environment together; Site Profile remains the operational authority when they differ.

Still external/deployment-governed, and therefore not repairable by repository code alone:
- Developer execution needs host-provided `prlimit` plus `bubblewrap` or `unshare-net`; no unsandboxed fallback is allowed.
- External MCP reconnect/initialize/tools-list evidence, browser-provider acceptance and frontend performance samples must be observed on the installed candidate.
- Search Profile creation, Google Drive connection, governed Context source/assets and Brand Core content are product/site configuration, not synthetic readiness.
- Provider behavioral receipts, artifact authority and owner-governed high-risk canaries must be produced against exact live artifacts.
- Exact-head owner attestation remains a human Release Verdict gate and cannot be inferred from prior PRs or generated by runtime code.
- Production readiness, Production mutation and generic raw-SQL Breakglass remain NOT_CLAIMED.

This reconciliation is intentionally lane-aware: repository closure may be DONE while the corresponding live/provider/host evidence remains EXTERNAL_PENDING. Neither state is allowed to impersonate the other.

## Evidence and limits

Local regressions exercise real service methods with WordPress/provider fixtures. They cover cold option storage, failed writes, admin alias attacks, passive registration, provider model source provenance, worker resumption/signature tampering/profile races, capability-local isolation, and zero-delta manual replacement versus schema/grant/actor/package drift.

A structural observation is not a behavioral certificate. The observer does not invent an automatic disposable canary for adapters without a governed fixture/executor. Elementor's bounded widget-settings bridge does not weaken that rule: it only lets the already-governed behavioral runner execute and rollback its exact approved probe; normal writes still require certified capability evidence. The existing behavioral recertification runner continues to require real before/after readback, verified rollback, approval finalization and an artifact-bound signed receipt. High-risk capabilities remain gated. This release therefore does not claim universal SELF-CONVERGING operation or completion of all 30 provider write surfaces.

The external MCP initialize/tools-list observer and passive frontend sampling already exist and retain exact session/build and measurement requirements. A currently stale client session must really reconnect; server-side status calls cannot substitute for an external handshake. Browser Acceptance still requires an external browser provider. Google OAuth re-consent, first Brand Core authority and host sandbox binaries require their actual external actions.

## Live installation and acceptance

The live installation observed on 2026-10-05 is rc.94, exact source `b43a90bab987c609792c8e410d57080b3dc4a2ae`. Its stale rc.93 authority binding was repaired through the exact Staging plan and read back: session state HEALTHY, current candidate matched, 66/66 Write grants, unchanged Write inventory fingerprint, no new grants/subjects/agents in the binding primitive, no Production mutation and no generic raw-SQL Breakglass. A healthy authority baseline was captured. This does not claim that rc.95 source changes are already installed, or that all external/provider acceptance gates passed.

After the trusted rc.95 release is installed, verify all nine MCP endpoints, Brand Profile creation, canonical and legacy admin paths, three frontend samples and real external browser acceptance. Do not turn disabled Content Pipeline validators on before their source/SEO/browser prerequisites are evidenced. Developer host sandbox prerequisites, Google consent, first Brand Core/source selection, actual provider canaries and external MCP/browser evidence remain concrete external or governed operations, not synthetic readiness records.

The WPML taxonomy notice and Elementor Pro domain-license notice are external provider operations, not evidence that the MAD4B core failed. Taxonomy hierarchy repair requires the actual affected parent/translation evidence; license repair requires the owner's legitimate Elementor connection. No notice is suppressed and no production mutation is authorized.
