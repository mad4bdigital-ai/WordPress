# MAD4B Unified Operation Policy — source review candidate

**Scope:** source-only portable snapshot of the VPS Staging implementation as of 2026-11-01. This PR is NOT a production deployment, native executor certification, or authorization of a company operation.

## Problem and fix

The older Universal Resolver stores `operations` for specific SSH / release-observer transport capabilities. ETG Staging's read-only observer has `operations: []`; ARE Staging has legacy scoped SSH capabilities. Neither value is an Original Core authorization decision for `plugin.install` or `content.write`. Treating them as one policy leads to false denials or false grant claims.

`company-unified-operation-policy.py` projects company+environment+site identity, per-operation decisions from the live original WordPress Core, original 305 transport/grant readback, and the independent legacy transport observations. It explicitly cannot create grants, execute jobs, or certify an original 313 receipt.

The mirrored `universal-runtime/resolver.py` changes the transport field semantics, supports generic exact-site registered read-only observers, and exposes the core/transport separation in the read-only status UI. The former ETG-specific observer dispatch is no longer required.

`test_resolver.py` corrects a pre-existing test counting every open review: the identity-resolution assertion now queries exactly the identity review, independently of the site-binding review. The prior broad test failed even against pre-change resolver source.

## VPS acceptance evidence

- Connected VPS source: 49/49 tests PASS for `test_resolver.py`, `test_multi_transport_authority.py`, `test_etg_central_integration.py`, and `test_etg_staging_release_observer.py`.
- Unified operation-policy isolated selftest: 10/10 PASS.
- 5 companies and 7 targets in live read-only snapshot; original 305 active transport/grant pair is not inferred from observer status.
- systemd unit `mad4b-company-unified-operation-policy.service` first invocation PASS; timer enabled and active.
- No production writes, no ETG Signed V4 install, and no owner grant/312/313 certification.

## Source layout and limitations

Files under `universal-runtime` are the **actual VPS Staging source snapshot** (including imported supporting modules and regression tests), **not** the canonical packaged WordPress plugin. They depend on site-local paths, original Core, registered SSH and external runtime data. The separate GitHub Actions workflow is deliberately hermetic: compilation and source-contract tests only. The full 49-test live acceptance is NOT claimed as a hosted CI result.

Reviewers must reconcile these source files with the release-owned executable architecture before merging or shipping a package. Keep existing WordPress plugin source and all Production environments unchanged until separate build, governed apply, and independent readback gates pass.

## Authority boundaries

1. Host/SSH reachability is transport authentication, never a WordPress actor or grant.
2. Original Core decides `company + environment + site UUID + operation`.
3. Any native shell application requires an independently authorized executor and original grants/receipts (305 → 306/312 → 313).
4. Empty read-only observer `operations` does not make an entire company globally read-only, and nonempty legacy `operations` does not authorize company writes.
