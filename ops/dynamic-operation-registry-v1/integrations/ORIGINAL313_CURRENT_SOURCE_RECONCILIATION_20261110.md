# Original 313 consolidated source reconciliation — 2026-11-10

Scope: source-only candidate in the first-party Company Code Release Git worktree.
No Original 305/306 Job, Grant, active worker change, PHP plugin mutation, schema DDL, or new 313 certification was produced by this source update.

## Observed provenance

- Original tracked consolidated candidate SHA256: `c0d93c133f538e8323c999a2512824b965e27568e25aac7a69347942b452e0f9`.
- Active VPS original 313 worker SHA256: `df7394fd2d9ecdae81fcf689af21a10016f91abe99450a454a90e1b09af162a1`.
- Reconciled source candidate SHA256: `df7394fd2d9ecdae81fcf689af21a10016f91abe99450a454a90e1b09af162a1`.
- Source AST comparison: one added function (`sign_existing_original_306_claim`), modifications confined to `claim_authorized_job` and `main`; other top-level functions unchanged.

## Security boundary retained

Remote claims are restricted to an exact enrolled `hostinger_ssh_wpcli` target, fixed read-only command digest, sealed argv hash, signed Original 305/306 job type, site UUID, current lease and fresh Original 312/313 checks. The source guard uses an exact SHA256 pin and refuses unknown future files. An existing reservation is not a license to replay a mutation.

The two older release copies (`mu-deploy-v1` and `plugin-install-v1`) still do not match this consolidated worker. They are **not** replaced as part of this source reconciliation. No generic write authorization should be inferred from this file or its static tests.

## Acceptance

`PYTHONPATH=. python3 -m unittest discover -s tests -p 'test_*.py' -q`: **80/80 passed**, including negative remote-claim tests. This is source regression acceptance, not live release certification.

## Outstanding deployment governance

The active original-328-source-release Systemd aggregate currently stops at its first 305 intake guard because several PHP source guards are pinned to older vetted candidates. Those guards must be independently reconciled; force-running the aggregator or allowing an unknown hash is not an acceptable repair. Signed release packaging, guarded source promotion, and independent post-deployment receipts remain separate.
