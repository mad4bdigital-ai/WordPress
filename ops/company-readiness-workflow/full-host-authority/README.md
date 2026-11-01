# MAD4B Universal Full Host Authority — dynamic source candidate

## Decision implemented

For **every company environment registered by the Original MAD4B Core**, advertise `full_host_authority` as the default *requested capability preset*, including Shell, WP-CLI, SQL, plugin install, content, services, native executor, Broker, and MCP. The company catalog is dynamic; there are **no ETG, ARE, Wayfa or other tenant-specific constants**, no per-site provisioning scripts, and no wildcard credential or global tenant grant.

`full_host_authority.py` consumes three independently obtained, verified snapshots:

1. `catalog.json`: `{ "contract": "mad4b.original-company-site-catalog.v1", "target_count": N, "targets": [...] }`. Each target has `company`, `environment`, `site_uuid`, `canonical_origin`, and `host_id`.
2. `host-policies.json`: mapping from `host_id` to existing `mad4b.native-vps-operator.v1` host-policy snapshots. It does not modify those policies.
3. `verified-evidence.json`: mapping `company:environment` to `mad4b.company-full-host-authority-evidence.v1` projections emitted by the **actual independent owner/manager/broker verifiers**, not user-supplied claims. These projections contain site binding, dynamic WordPress actor, assignment revision, verified signatures and 305/native-executor readback.

Example:

```sh
python3 full_host_authority.py \
  --catalog catalog.json \
  --host-policies host-policies.json \
  --verified-evidence verified-evidence.json
python3 -m unittest discover -s . -p 'test_*.py' -v
```

### Security and operational boundaries

- All enrolled staging **and production** sites are discovered, but **production activation remains independently approved**, even though its capability *preset* includes Full Host Authority.
- `allow_production_mutation` is proposed only for each exact production site; no global flip, no universal `*` grant.
- Requested Full capabilities **never constitute authorization**. Live enforcement remains with Original Core's owner/manager authority, host signature, per-site Host Scope, 305/306/312/313 and WordPress operation admission.
- Existing legacy SSH scopes and observer `operations: []` do not confer Original Core authority.
- `ADMISSION_EVIDENCE_COMPLETE_READ_ONLY` means a read-only comparison of independently verified records; it **does not certify an operation** or grant execution.
- Full Shell and SQL also require the host's existing global `allow_general_shell` / `allow_raw_sql` flags; this tool never changes them.
- This source candidate has **no `apply`, no shell executor, no signing keys, no database write, and no production deployment**. An independently reviewed privileged policy reconciliation service and certified issuer integrations are required to activate.

### Integration work still required

Connect the source to the **live authenticated** Original Core company catalog, **independently verified** owner/manager/provenance evidence, signed host policy ledger, scoped native executor, and reconciled Original 305 pair. Add staging acceptance with rollback and independent 313 receipts before production rollout. Do not treat a fabricated evidence JSON as permission to execute.
