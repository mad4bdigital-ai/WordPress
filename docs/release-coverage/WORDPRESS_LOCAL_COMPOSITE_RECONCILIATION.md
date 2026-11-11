# MAD4B — Local composite WordPress source integration, 2026-11-11

## Exact source lineage (not operational authorization)

- Base WordPress Git source: 7974d46b8ec203e728646a7fbac5fd334ae2f4c2 — integration containing the Git histories of PR #797 and PR #787. This is not the full Tours.dev live tree.
- Tenant Sync: 27 entirely absent source paths imported unchanged from PR #798 head df112f08b848c25483829c15bc07e02aa2c4eb25, including the 26-file plugin tree and its environment-neutrality source test.
- Original313: 7 entirely absent source paths imported unchanged from PR #800 head 8c4ce575335949dde860198f28851da45b9aec68: three reviewed-source candidates (including pinned older license predecessor), three review tests, and their historical consolidated-release test dependency. The historical promotion tool is source-only and its pinned df7394 SHA does not match the newer live c98aa worker. No use of its apply path.
- No ancestor branch merge for #798/#800, because attempting a complete merge from these branches against the composite base raised conflicts in security/authorization and registry files. This is an additive, exact-blob feature-source port; it does not resolve those conflicts.
- SHA-preserving provenance is recorded for every imported file in source-overlay-20261111.json. tools/verify_source_overlay_readonly.py checks each source Git object, source file, exact digest, category allowlist, and absence in base without writing any authority or website state.

## Acceptance

- 26 Tenant PHP files successfully parsed with PHP from the existing isolated Staging runtime.
- The direct source acceptance tests passed: 7 Tenant environment-neutrality, 5 Original313 receipt-only preclaim, 5 Original313 receipt-only adapter, and 4 historical Worker promotion tests, with separate overlay-verifier test coverage.
- The four in-plugin PHP/WP-CLI integration fixtures were NOT executed against Tours.dev or any company. These require independent WordPress runtime readback and site-specific acceptance before publication.
- The source integration never executes an issuer, a signed 312 job, Original313 apply, WordPress mutation, or any company release. It adds no new global OAuth or environment-based privileged grant.

## Hard HOLD boundaries

1. The live Tours.dev site plugin source is not identical to the PR #799 candidate; major modules remain missing or different relative to live. Do not deploy this source tree over live WordPress.
2. Added Tenant source is not proof the plugin is installed, activated, configured, or bound to each company; avoid replacing current company-specific Options, Elementor/JetEngine definitions, WCML rates, Fluent Forms, SEO and identities.
3. The current signed Original313 Worker c98aa is NOT replaced by either new candidate. Implement exact first-party signed role/claim/readback before any source promotion.
4. Original318 v82, independent root revision/high-water and Issue/Revoke operations remain separate blocked execution tasks. This composite does not certify them.
5. Every other open GitHub branch and remaining local WordPress head requires feature-level coverage and conflict review before a full platform release.

Verdict: SOURCE_RECONCILE_CANDIDATE_ONLY, GLOBAL_RELEASE_HOLD. No publisher or live mutation.
