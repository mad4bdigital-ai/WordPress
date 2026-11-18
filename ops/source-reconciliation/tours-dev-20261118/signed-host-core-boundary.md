# Tours.dev signed-Host Agent core preservation

This source-only integration selectively recovers the live signed WP-CLI
`Host_Agent_Bridge` and exact `CLI_Execution_Context` from independently
captured SHA-256 source, and adds only `review_asset_by_host_agent()` to
Git-owned Context Authority. The complete live bootstrap is **not** copied.

Core Host Agent is NOT automatically entitled to write. A pinned public key,
exact enrolled Site Profile, host/deployment binding, real non-impersonating
WP-CLI context, fresh Ed25519 envelope, nonce anti-replay and exact Agent/Ability
grant are separate requirements. Registration never installs a key, creates an
Agent, publishes Media, accepts an OAuth identity as the signer, or certifies
Production readiness. The live source's signed `bind` operation can request
exact grants; it must never run without an independently signed host envelope.

The tourism-specific Media providers (Media Intake, Visual Strategy Proposal,
Media Reconcile Proposal, Media Closure) were subsequently removed from the
installed live core and their last contents preserved in the private v3
snapshot. The new live Host Agent returns an explicit unavailable error when
the corresponding optional provider class is missing. Do NOT reimport those
four provider files as mandatory core dependencies or infer company-specific
Media permissions from their historic presence. Independent company add-on
source and activation review is required.

The source fixture executes entirely in isolated PHP CLI with a generated
test-only ephemeral keypair. No private key, WordPress credential, tenant data,
Host authority anchor, database mutation or real signed Host request is
committed or issued. One-time nonce storage is only a PHP in-memory fake.

This is a release **candidate slice**, not a full-plugin release. The frozen
995-file inventory must be compared against any integrated full artifact,
independently reviewed differences pinned, and release CI, owner attestation
and staging runtime evidence completed before deployment.
