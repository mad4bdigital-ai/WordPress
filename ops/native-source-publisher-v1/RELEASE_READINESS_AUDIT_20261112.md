# Native Source Publisher: release readiness audit

Current state: **HOLD**, read-only candidate. This audit is a safety check, not a
publisher, signer, original worker, or signed release receipt.

Run from the WordPress Git worktree:

    PYTHONDONTWRITEBYTECODE=1 python3 ops/native-source-publisher-v1/audit_release_readiness.py

The command returns exit code 2 while any blocking condition is observed.
The executable does not open a write transport or create an approval. All
file-read source and count mutation probes use temporary Git files and
in-memory plans, never the installed WordPress code or database.

Dynamic discovery: the audit reads every candidate descriptor under
catalog/generated. It reports unregistered operations by comparing IDs with
the installed Original306/313 index. It does not hardcode the name of the
sample source operation. Index presence is not proof of provider authenticity;
first-party independent enrollment and signed Original313 readback remain
separate requirements.

The two security probes, observed 2026-11-12:

- A plan may use the immutable Git content from a different relative source
  path while targeting an otherwise allowed file. This is permitted by the
  current core semantics when the target is explicitly in the policy. MAD4B's
  stricter chosen source-publisher policy requires matching relative paths
  unless a separately reviewed source-to-target mapping is explicitly
  authorized by a provider.
- Changing the derived changed_count in a preexisting plan is not rejected by
  the immutable plan digest alone. Its value must be derived again from the
  signed source/preimage hashes at the publisher's final admission step.

No claim of unauthorized live deployment follows from either probe: all
Original306/312/313 authorization conditions still apply. But code must not
be certified publishable until the stated invariants are implemented,
verified, and the correct first-party provider is enrolled via its approved
channel. Prior safety checks denied an attempt to edit these executor guards;
this audit does not circumvent that denial.

The audit gate also checks that the site-native policy and read-only Central
bridge have actually been installed. Currently they have not. An exit code
zero on a future local source audit would still NOT constitute release
authorization: a separately signed Master Admin/Original312/Original313
workflow remains mandatory.
