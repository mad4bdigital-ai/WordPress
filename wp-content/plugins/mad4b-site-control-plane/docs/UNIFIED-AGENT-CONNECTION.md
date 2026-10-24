# Unified Agent Connection - MAD4B

The existing Connect to ChatGPT admin page presents Connect AI Agent. An administrator chooses an existing registered agent or creates one just in time. OAuth, Application Password and dual mode are offered according to the actual runtime.

OAuth still requires an external ChatGPT consent handshake. A saved setup receipt never claims that external authorization succeeded.

To opt in to trusted-server Application Password provisioning, configure MAD4B_AGENT_CONNECTION_VAULT_DIR outside ABSPATH and WP_CONTENT_DIR. The pre-existing absolute Vault directory must be mode 0700. The plugin writes an exclusive per-site/per-user JSON credential mode 0600. Password bytes never appear in the browser or WordPress options. A separate trusted server process needs filesystem access.

Both actor and Site Profile binding are checked. Only discoverable, mounted read abilities may receive an exact environment-scoped grant; no write, Production, Developer or Breakglass grant is minted. The Agent identity is distinct from its OAuth or Application Password method. To rotate a stored Application Password, revoke it and explicitly issue again.

Not yet live certified: remote ChatGPT connector creation and OAuth consent, real external Basic authentication, Vault consumer configuration, or external site functional acceptance. No automatic remote connection is claimed.

## Optional AI-only Brand Context Review

The Connect AI Agent page also exposes a **separate** Review Policy form: Human review, Human + AI, or AI-only. The review policy is never auto-enabled as a side effect of connecting an agent. A logged-in administrator must explicitly confirm delegation, and the wizard delegates the change to the **existing** MAD4B Context Authority contract using the **canonical Site Profile governed-write Agent**, on Staging only. The wizard never issues the exact write grant by itself.

On AI-only, an authenticated OAuth Bearer Agent holding the exact Staging context review grant can submit `approve`, `needs_changes`, or `reject`. Final approval requires source integrity SHA-256, expected registry revision and authority-manifest fingerprint, complete normalized source, verified classification and reasoned review evidence. Classification proposals are logged but cannot silently modify categories or quality policy. The audit log records Agent identity and decision. **Publication and Brand Context materialization remain separate.**

**ETG documents 66145, 66146 and 66147 were not reviewed or approved by this code change.** Their source gaps should result in `needs_changes` until evidence and classification checks pass. Live OAuth connectivity, exact permission grants and document-level acceptance are distinct site operations, not GitHub CI assertions.

Failed Agent activation after issuing a new Application Password triggers a bounded rollback: validate the exact just-issued Vault UUID, revoke it through WordPress, then delete that private Vault record. If either step fails, the administrator receives a cleanup-required error; no success receipt is written. An unrelated saved credential is never revoked by this path.
