# MAD4B ChatGPT — automatic connection identity binding

## Outcome
A user connects the MAD4B ChatGPT plugin through the existing OAuth flow.
The plugin captures the exact approved OAuth **client**, enrolled WordPress user,
Site Profile UUID/digest, canonical origin, and MCP resource without requiring
manual input of a ChatGPT App ID or additional WordPress configuration screens.

The OAuth CIMD client ID, `https://chatgpt.com/oauth/client.json`, is **not**
the private ChatGPT app ID `asdk_app_...`. OAuth does not transmit the latter.
The plugin NEVER guesses a private app ID or fills Site Profile with one.
`plugin_asdk_app_...` remains a supported legacy explicit optional field.

## Automatic capture lifecycle
1. **New connection:** successful OAuth authorization-code exchange (or
   eligible refresh) invokes `MAD4B_SCP_ChatGPT_Auto_Binding::observe_local_token_issued`
   before returning the token response. Captures no tokens, credentials or
   unknown client-supplied fields.
2. **Existing connection:** the next protected MCP request with a correctly
   verified Bearer token calls `observe_verified_mcp_request`. This
   auto-reconciles existing accounts without reconnecting or editing settings.
3. **Exact identity checks:** only the canonical ChatGPT CIMD client, the
   enrolled current-origin Site Profile, matching HTTPS MCP resource, valid
   `mad4b:read` scope and an enrolled administrator are accepted.
4. **Storage:** separate non-authorizing WordPress option
   `mad4b_scp_chatgpt_auto_binding_v1`; never edit Site Profile UUID,
   revision, grant matrix, package binding, client credentials or scopes.
   Avoid hot-path write amplification; renew observation no more frequently
   than every 24 hours for unchanged identity.
5. **Readback:** admin diagnostics show `Automatic OAuth client binding:
   bound` while explicitly keeping MCP session certification separate.
   `connection_fingerprint` is a deterministic, non-authorizing hash.
6. **Drift:** profile digest, site UUID, resource, user or age mismatch
   invalidates stored observation. A new *verified* OAuth exchange or MCP call
   may observe the new identity; no blind migration or foreign-site adoption.
7. **Phase A:** governance-approved Acceptance/Skills enrollment may recognize
   the current authorized, exactly bound OAuth client instead of requiring
   manual `chatgpt_app_id`. Its own one-time approval, exact runtime/source
   requirements and Staging/Production conditions still apply.

## Explicit boundaries
- OAuth does **not** create Site Profile for an unknown or cloned origin.
  Existing portable read-only fallback remains distinct.
- App ID is not exposed by OAuth; never manufacture `asdk_app_...`.
- No Write/Developer/Breakglass grants, Production opt-in, governance gates,
  deployment binding or host execution are created.
- An OAuth client observation is not evidence that MCP
  `initialize`/`tools/list` succeeded. Use the existing external handshake
  evidence for independent session certification.
- This code exists in PR #258 until it has been packaged and installed;
  GitHub source changes do not repair already-installed WordPress versions.
- If MCP tools fail internally even after connection, diagnose REST/transport
  and Site Profile separately; a ChatGPT connected-account listing alone
  does not prove that tools work.

## Test commands
```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-chatgpt-auto-binding.php
php wp-content/plugins/mad4b-site-control-plane/tests/chatgpt-auto-binding-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/site-profile-feature-reenrollment-runtime.php
```

These are tests to be run; adding them to CI is not an assertion that CI
or Staging acceptance has already passed.
