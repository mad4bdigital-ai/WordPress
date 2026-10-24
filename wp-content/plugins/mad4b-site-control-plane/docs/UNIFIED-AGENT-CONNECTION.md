# Unified Agent Connection - MAD4B

The existing Connect to ChatGPT admin page presents Connect AI Agent. An administrator chooses an existing registered agent or creates one just in time. OAuth, Application Password and dual mode are offered according to the actual runtime.

OAuth still requires an external ChatGPT consent handshake. A saved setup receipt never claims that external authorization succeeded.

To opt in to trusted-server Application Password provisioning, configure MAD4B_AGENT_CONNECTION_VAULT_DIR outside ABSPATH and WP_CONTENT_DIR. The pre-existing absolute Vault directory must be mode 0700. The plugin writes an exclusive per-site/per-user JSON credential mode 0600. Password bytes never appear in the browser or WordPress options. A separate trusted server process needs filesystem access.

Both actor and Site Profile binding are checked. Only discoverable, mounted read abilities may receive an exact environment-scoped grant; no write, Production, Developer or Breakglass grant is minted. The Agent identity is distinct from its OAuth or Application Password method. To rotate a stored Application Password, revoke it and explicitly issue again.

Not yet live certified: remote ChatGPT connector creation and OAuth consent, real external Basic authentication, Vault consumer configuration, or external site functional acceptance. No automatic remote connection is claimed.