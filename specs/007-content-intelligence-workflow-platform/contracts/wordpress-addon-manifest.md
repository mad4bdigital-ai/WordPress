# MAD4B WordPress Add-on Manifest

Contract: `mad4b.wordpress-addon-manifest.v1`

A manifest is a **governance declaration, not a PHP sandbox or security boundary**. An add-on remains ordinary WordPress PHP and therefore also requires code review, static policy checks, runtime certification and execution fencing.

## Required shape

```json
{
  "contract": "mad4b.wordpress-addon-manifest.v1",
  "addon_id": "mad4b-example-addon",
  "plugin_file": "mad4b-example-addon/mad4b-example-addon.php",
  "base_provider": {
    "provider_id": "provider-id",
    "identity_source": "MAD4B provider registry"
  },
  "compatible_versions": {
    "constraint": "bounded discovery range only",
    "unknown_version_behavior": "FAIL_CLOSED"
  },
  "extension_points": [
    {
      "type": "hook|filter|api|rest|wp_cli|provider_php_api",
      "name": "exact extension point",
      "required": true,
      "accepted_args": 1,
      "lifecycle_phase": "explicit",
      "semantics": "read|bounded_write|execution"
    }
  ],
  "capabilities": [],
  "data_ownership": {
    "provider_owned": [],
    "addon_owned": [],
    "schema_owner": "addon",
    "migration_owner": "addon",
    "uninstall_behavior": "retain|export_then_remove|approved_remove"
  },
  "authority_impact": {
    "inherits_production_authority": false,
    "adds_generic_shell": false,
    "adds_raw_sql": false
  },
  "rollback": {
    "disable_is_safe": true,
    "capability_level_compensation": {},
    "irreversible_capabilities": []
  },
  "certification": {
    "exact_provider_version_required": true,
    "exact_addon_version_required": true,
    "extension_points_verified": true,
    "runtime_behavior_verified": false
  },
  "tests": {
    "compatibility_contract": "required",
    "runtime_certification": "required",
    "negative_space_scan": "required"
  },
  "portability": {
    "vendor_files_modified": false,
    "exit_path_documented": true
  },
  "supply_chain": {
    "source": "wordpress.org|vendor|private",
    "publisher": "publisher identity",
    "license": "license identifier",
    "distribution_mode": "external|bundled|user_supplied",
    "package_sha256": "exact package sha256 when certified",
    "update_channel": "channel",
    "reviewed_version": "exact reviewed version",
    "security_review_status": "PASS|FAIL|UNKNOWN"
  },
  "network_access": {
    "allowed_hosts": [],
    "data_categories": [],
    "data_governance_required": true,
    "secret_access": "none|bounded"
  },
  "multisite": {
    "support": "unsupported|per_site|network",
    "network_activation_allowed": false
  },
  "performance_budget": {
    "max_added_queries": 0,
    "max_sync_wall_ms": 0,
    "unbounded_autoload_option_allowed": false
  },
  "observability": {
    "status_required": true,
    "last_failure_required": true,
    "certification_state_required": true
  },
  "failure_policy": {
    "bounded_retries": true,
    "circuit_breaker_or_quarantine": true,
    "reconcile_before_retry_after_uncertain_write": true
  },
  "release_ring": "shadow|canary|active",
  "certified_pairs": [
    {
      "provider_version": "exact",
      "addon_version": "exact",
      "extension_points_sha256": "64 hex",
      "addon_main_file_sha256": "64 hex"
    }
  ]
}
```

## Enforcement rules

- Version ranges are discovery/candidate hints only; they never authorize mutation.
- Mutation requires an exact certified `(provider_version, addon_version)` pair and current runtime provider certification.
- Provider or add-on update, activation/deactivation, package-fingerprint change, or extension-point fingerprint drift invalidates certification and stale plans.
- Execution must revalidate the pair/certification fingerprint at the commit boundary; drift means re-plan and re-approval.
- Add-on activation never creates Production authority.
- Never patch vendor files at runtime.
- Direct shell, raw SQL, unbounded filesystem/database/content mutation, and unbounded outbound HTTP are forbidden unless a separately reviewed bounded capability explicitly owns them.
- External data transfer must obey Data Governance, allowed-host and data-category policy.
- Rollback is capability-level: disabling the add-on does not imply that earlier mutations were compensated.
- Multisite is unsupported unless explicitly declared and certified.
- Repository tests never replace exact-version Staging/runtime certification.
