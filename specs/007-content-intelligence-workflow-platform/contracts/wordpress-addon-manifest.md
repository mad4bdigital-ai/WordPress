# MAD4B WordPress Add-on Manifest

Contract: `mad4b.wordpress-addon-manifest.v1`

Use this manifest for a MAD4B-owned add-on that extends a third-party WordPress provider without modifying vendor files.

```json
{
  "contract": "mad4b.wordpress-addon-manifest.v1",
  "addon_id": "mad4b-example-addon",
  "base_provider": {
    "slug": "provider-slug",
    "identity_source": "plugin/runtime discovery"
  },
  "compatible_versions": {
    "constraint": "explicit bounded range",
    "unknown_version_behavior": "FAIL_CLOSED"
  },
  "extension_points": [
    {
      "type": "hook|filter|api|rest|wp_cli|provider_php_api",
      "name": "exact extension point",
      "required": true
    }
  ],
  "capabilities": [],
  "data_ownership": {
    "provider_owned": [],
    "addon_owned": []
  },
  "authority_impact": {
    "inherits_production_authority": false,
    "adds_generic_shell": false,
    "adds_raw_sql": false
  },
  "rollback": {
    "disable_is_safe": true,
    "mutation_compensation_contract": null
  },
  "certification": {
    "exact_provider_version_required": true,
    "extension_points_verified": true,
    "runtime_behavior_verified": false
  },
  "tests": {
    "compatibility_contract": "required",
    "runtime_certification": "required"
  },
  "portability": {
    "vendor_files_modified": false,
    "exit_path_documented": true
  }
}
```

Rules:

- Prefer provider-supported extension points.
- Never patch vendor files at runtime.
- Unknown provider versions or missing required extension points fail closed.
- Add-on activation never creates Production authority.
- Mutating capabilities remain subject to MAD4B approval, certification, audit, rollback, and recovery policy.
- Repository tests do not replace exact-version Staging/runtime certification.
