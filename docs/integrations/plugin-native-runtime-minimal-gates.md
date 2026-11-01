# Native WordPress plugin operation — minimal gates

**Contract:** `mad4b.plugin-native-runtime-minimal-gates.v1`  
**Scope:** installing, activating, loading, deactivating and updating ordinary WordPress plugins.

## Decision

Standard plugin operation must not require a MAD4B release certificate, an independent certifier, an owner attestation, a signed release receipt, or a separate commercial launch approval. The native WordPress plugin manager remains the source of truth for normal plugin activation and deactivation. The Control Plane is not a mandatory prerequisite for third-party plugins to function.

### Required for actual operation

- Installed plugin files and correct WordPress/PHP compatibility.
- WordPress-native user capabilities and nonces for privileged plugin management.
- Declared required plugin dependencies.
- Basic integrity/error detection and compatibility checks.
- Authorized credentials and narrowly scoped permissions for operations that actually access external services or change data.

### Optional, not startup prerequisites

- Release certificates, independent external certification, owner sign-off receipts, and release provenance ledgers.
- Governance dashboards, additional review documents, sandbox proof packages, and staging-to-production attestation bundles.
- Agent/MCP admission workflows: these apply only when a privileged remote agent is explicitly used, not to standard WordPress page loading or native plugin activation.

Optional Governance's new-install default is **off**. Existing explicitly configured options are not silently overwritten.

This contract does not bypass site authentication, authorization, data protection, secret validation, or safe update/rollback requirements when those requirements are intrinsic to the requested operation. No Production deployment is authorized by this document.
