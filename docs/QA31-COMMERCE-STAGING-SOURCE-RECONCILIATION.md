# QA31 — Commerce Staging ↔ GitHub source reconciliation (non-authorizing)

This inventory compares the **exact pinned build** of ETG Commerce Staging at
`0.4.0-rc.118` with the **GitHub PR #557 code baseline**
`86f0b4f40ea86dbc714c89f32d9d94cc8a2b2da7`, which still declares
`0.4.0-rc.104`. Different version numbers alone are not enough to select
which source is correct.

## Verified inventory

| Category | Files | Rule |
| --- | ---: | --- |
| Shared files with matching SHA-256 | 778 | Preserve |
| Staging-only protected source | 46 | Import only after provenance, capability/security review |
| GitHub-only protected source | 3 | Preserve GitHub, verify compatibility on staging |
| Divergent protected source | 48 | Three-way semantic review and regression |
| Generated/build differences | 5 | Regenerate from approved source |
| GitHub-only tests/support | 1,067 | Preserve test history; do not copy runtime over it |
| Verified Staging build manifest | 877 | No package hash drift |

The 48 divergent source files include 16 **additive-only** cases, 28
text-replacement cases, and four removal/mixed cases. The private VPS
review candidate incorporates 46 Staging-only sources and 16 exact
line-additions, but **is not a distributable package or certified merge**.
Its 679 PHP files passed syntax checks, not behavioral or live-site certification.

## Authority and ordering

1. GitHub stays the source of reviewed, versioned code. Staging is evidence of
   deployed behavior, **not** permission to overwrite GitHub.
2. Every imported or merged PHP source requires same-PR regression, exact blob
   capability coverage and governance acceptance. Test and provenance files
   must be retained; do not bulk replace `wp-content/plugins/`.
3. For remaining 32 non-additive conflicts, review the origin of each code path,
   including Owner Activation, Site Profile, WooCommerce integration, MCP and
   company communication. No automatic "prefer Staging" policy.
4. Repeat pinned source inventory, rebuild package provenance, run CI,
   install only on a disposable Staging environment with rollback, and retest
   actual roles/REST/WPML/WooCommerce/Fluent Forms behavior.
5. Signed exact-head owner approval and independent Production promotion are
   **separate**, and cannot be inferred from this report.

## Tooling

- `tools/qa31_source_reconciliation.py`: verifies the Staging package SHA
  manifest and local Git HEAD, then produces a SHA-only, path-only inventory.
- `tools/qa31_release_source_gate.py`: validates the curated evidence against
  GitHub files. A version mismatch, missing module or unexpected source hash
  leaves the release **BLOCKED**.
- `tests/qa31_release_source_gate_contract.py`: hermetic negative tests for
  tampered source, unsafe paths, duplicate coverage and unauthorized evidence.
- `MAD4B QA31 Source Parity Evidence` workflow: PRs validate evidence; manual
  `require_parity` uses a **strict blocking** gate. The PR check does not grant
  release authority.

The evidence is deliberately non-authorizing and contains no source bodies,
SMTP credentials, user data or company database records. The live site,
Production, and `master` remain unchanged during reconciliation review.
