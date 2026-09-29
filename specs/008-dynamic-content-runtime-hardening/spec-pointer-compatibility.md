# Spec Pointer Compatibility

The repository historically uses `.specify/feature.json` as a singleton pointer, while legacy ETG operational CI still reads Feature 001 release fields directly from that file.

Feature 008 therefore preserves the legacy top-level `status`, `target_version`, merge/production flags and required ETG gates while adding explicit `active_feature_id`, `active_feature_status`, active Feature 008 directory/branch, and a nested `legacy_feature_001` record.

This is a compatibility bridge, not authority inheritance:
- Feature 001 release identity remains readable by its existing CI.
- Feature 008 remains the active Spec Kit through feature_directory/feature_branch.
- Required gates are a union so neither feature loses release checks.
- No legacy Feature 001 authority is granted to Feature 008.
- Future cleanup should migrate repository workflows to feature-scoped manifests rather than a singleton global pointer.
