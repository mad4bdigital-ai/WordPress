# Staging MCP post-update recovery: exact build, Skills certification, and owner gate

This is an **operator-initiated Staging-only** runbook. It does not change OAuth trust, grant Write/Developer/Breakglass, clear old convergence checkpoints, or bypass the automation kill switch. Production is excluded.

## The verified ETG incident (25 October 2026)

The authenticated ChatGPT connector returned an internal tool failure. Bounded Staging-only REST diagnostics recorded HTTP 503 with origin code `mad4b_mcp_runtime_restart_grace`. The checkpoint was a pending manual self-update from rc.102, while the installed build was rc.104. The checkpoint was blocked by `mad4b_automation_automation_kill_switch`; it was **not** safe to clear it or replay the old update.

Independent convergence evidence showed `managed_skills:pending` because the *persisted, build-bound certification* was stale, although live managed Skills were ready. The initially installed package source SHA `c9ad6012f9a07c998795839e201888565dadc386` also differed from the approved master artifact source `68ce3a12bf1bcc476c7ca8ab58c696a7cdadb504`. Installing the SHA-256 and manifest-verified `68ce3a12` package, then explicitly certifying its live-ready Skills, reduced required blockers to `authority_binding:owner_gate` and produced `historical_mcp_read_safe=true`. Authenticated ChatGPT calls to site info, tool discovery, and session-safe diagnostics then succeeded. The owner gate remained closed to new write authority.

## Exact preconditions

1. Verify the **exact Staging site** and active MCP Adapter; do not treat public HTTP 401 or OAuth metadata 200 as proof of authenticated tools.
2. Use an approved, SHA-pinned package from the GitHub Actions canonical distribution kit. Verify `SHA256SUMS`, archive inventory, provenance, install manifest, and active source identity. Do not use the release version string alone.
3. Back up the previous plugin directory for atomic rollback and store the previous Skill certification option **outside the WordPress webroot**, protected with file mode 0600.
4. Prove `MAD4B_SCP_Skill_Runtime_Certification::current_status()['ready'] === true` and current-build provenance is ready. If live Skills are unready, **do not** use recertification to hide missing files or provider reconciliation.
5. Retain the historical self-update checkpoint for audit. Never delete it, turn off the kill switch, or grant owner authority to unblock reads.

## Operator-only explicit certification

Use the trusted site SSH/WP-CLI environment after the exact package has been installed and activated. The operational script is at:

`wp-content/plugins/mad4b-site-control-plane/tools/staging-mcp-skills-recertify.php`

For an ETG Staging release based on the validated merge commit:

```bash
cd /home/u783534439/domains/egypttourgates.com/public_html/staging
wp option get mad4b_scp_skill_runtime_certification_v1 --format=json \
  --skip-plugins --skip-themes > /home/u783534439/.mad4b-staging-skills-cert-backup.json
chmod 600 /home/u783534439/.mad4b-staging-skills-cert-backup.json

export MAD4B_STAGING_MCP_RECERTIFY='CERTIFY CURRENT STAGING SKILLS'
export MAD4B_STAGING_EXPECTED_SHA='68ce3a12bf1bcc476c7ca8ab58c696a7cdadb504'
export MAD4B_STAGING_EXPECTED_ORIGIN='https://staging.egypttourgates.com'
export MAD4B_STAGING_CERT_BACKUP_PATH='/home/u783534439/.mad4b-staging-skills-cert-backup.json'

wp eval-file wp-content/plugins/mad4b-site-control-plane/tools/staging-mcp-skills-recertify.php --skip-themes
```

For other installs use the exact reviewed current source SHA, verified origin, and separate private backup path. Only run the script from WP-CLI; it rejects wrong environment, wrong source SHA, wrong origin, missing/outside-webroot backup, unready live Skills, and unavailable audit storage. Already-current certification produces `ALREADY_CURRENT` **without mutation**. A stale certificate is refreshed once with auditable exact-build readback. The historical checkpoint is checked for mutation and must remain unchanged.

## Acceptance and CI

- Require independent post-install `MAD4B_SCP_Runtime_Convergence::status()` and `restart_grace_status()` readbacks. An owner gate can legitimately remain closed; do not equate read success with permission to write.
- Call **authenticated** `mad4b_site_info` and `mad4b_tool_discover` from the actual ChatGPT connector. Optionally call `mad4b_session_safe_diagnostics` and distinguish a partial diagnostic response from a tool invocation error.
- The hermetic regression `tests/staging-mcp-skills-recertify-contract.php` verifies strict Staging/origin/commit gates, stale-only recertification, idempotence, and historical checkpoint preservation.
- The separate `tests/mcp-live-authenticated-gate.py` refuses to pass without a real OAuth bearer; do not put bearer tokens in CI logs or GitHub issues.
- If authenticated tool calls still fail, collect bounded 503 response codes and correlation IDs, not access tokens or request bodies; investigate any independent blocker before touching runtime state.
- Keep a rollback directory until the accepted Staging state is stable; **Production requires its own separate governance and must never inherit this repair.**
