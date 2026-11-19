# Wayfatours Production runtime-gate provenance preflight

Scope: **read-only diagnosis and source reconciliation**. Not an activation.

On 19 Nov 2026, the enrolled Production profile and audit storage were
ready, but production_mutation_enabled and production_auto_enable were
false. Actual owner-context manual plan returned
mad4b_runtime_gates_provenance_not_ready.

The installed rc.128 Manifest contains 955 files; 881 match and 74 have
newer/different bytes. None is missing. Original source commit
3e6ce498b75390bc900c2c083f92891b8433b774 was not found in available
local Git. There are also 99 unmanifested files, including 16 PHP sources.
55 of the 74 changed match Tours.dev; 19 differ. The selected rc129 source
is not a drop-in replacement for the complete installed package.

Root-only original backup, SHA classifications and 19-file differences:
 /opt/mad4b-company-provisioning/reports/wayfa-production-gate-reconcile-20261119/

Before enabling:
1. Preserve production deltas and classify unmanifested PHP and assets.
2. Reconcile the source in a clean approved Git commit; test and build the
   deterministic Control Plane package using the official source builder.
3. Deploy that reviewed package through exact CAS/native release and verify
   the manifest, source commit, build fingerprint and runtime equality.
4. Enrolled owner approves the exact Manual Runtime plan via a real browser
   login on the Production Site Profile screen. Do not forge an owner
   session or bypass the hash requirement with SQL or wp-config edits.
5. Keep production_auto_enable and all Raw SQL/DDL options disabled.
6. Separately admit and test the Wayfatours-native ContentJobs draft patch.

Run the read-only preflight script:

    python3 ops/wayfa-runtime-gate-preflight-v1/preflight.py \
      --plugin-root /path/to/mad4b-site-control-plane \
      --git-repo /path/to/wordpress-source

Optional --report creates an exclusively new mode-0600 JSON file.
