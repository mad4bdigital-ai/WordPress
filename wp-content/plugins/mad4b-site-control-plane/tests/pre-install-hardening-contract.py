#!/usr/bin/env python3
"""Static fail-closed contract checks for MAD4B Site Control Plane pre-install hardening."""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")

def require(source, needle, label):
    if needle not in source:
        raise AssertionError(f"missing {label}: {needle}")

def forbid(source, needle, label):
    if needle in source:
        raise AssertionError(f"forbidden {label}: {needle}")

def main():
    policy = text("includes/class-mad4b-scp-policy.php")
    abilities = text("includes/class-mad4b-scp-abilities.php")
    provider = text("includes/class-mad4b-scp-provider-contracts.php")
    base = text("includes/adapters/class-mad4b-scp-adapter-base.php")
    registry = text("includes/class-mad4b-scp-adapter-registry.php")
    servers = text("includes/class-mad4b-scp-servers.php")
    audit = text("includes/class-mad4b-scp-audit.php")
    elementor = text("includes/adapters/class-mad4b-scp-elementor-adapter.php")
    jetengine = text("includes/adapters/class-mad4b-scp-jetengine-adapter.php")
    bitflows = text("includes/adapters/class-mad4b-scp-bitflows-adapter.php")
    seo = text("includes/adapters/class-mad4b-scp-seo-adapter.php")

    # P0: normal WordPress MCP filesystem mutation cannot become source-code/server-config execution.
    require(policy, "is_code_or_server_config_path", "executable file classifier")
    require(policy, "mad4b_executable_file_mutation_denied", "executable mutation hard deny")
    require(policy, "array( 'uploads' )", "data-only mutable root default")
    require(policy, "mad4b_scp_mutable_data_extensions", "data extension allowlist")
    for token in (".htaccess", ".user.ini", "php.ini", "web.config", "phtml", "phar", "svg"):
        require(policy, token, f"filesystem deny token {token}")
    require(abilities, "MAD4B_SCP_Policy::can_mutate_file", "filesystem mutation policy invocation")

    # Provider mutation circuit breaker and authoritative runtime self-test.
    require(provider, "mutation_guard", "provider mutation guard")
    require(provider, "native_abilities_missing", "native ability missing blocker")
    require(provider, "verified_absent_ability_present", "new native ability blocker")
    require(provider, "required_providers", "required target providers")
    require(base, "mad4b_provider_contracts_unavailable", "missing certification authority fail-closed")
    require(base, "mutation_permission_callback", "central adapter mutation permission wrapper")
    require(registry, "core_ability_names", "core ability self-test inventory")
    require(registry, "provider_contract_blockers", "provider blocker reporting")
    require(registry, "required_provider_missing", "missing provider reporting")
    require(registry, "custom_server_registration_ok", "server registration verdict")
    require(servers, "registration_status", "custom server registration evidence")

    # Lifecycle mutation is disabled and empty-allowlist by default; stale state and multisite capabilities are mandatory.
    require(policy, "MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED", "plugin lifecycle master opt-in")
    require(policy, "mad4b_scp_plugin_lifecycle_allowlist', array()", "empty plugin lifecycle allowlist")
    require(abilities, "expected_active", "plugin lifecycle optimistic guard")
    require(abilities, "manage_network_plugins", "multisite network lifecycle capability")
    require(abilities, "mad4b_stale_plugin_state", "plugin lifecycle stale-state rejection")

    # Structured DB mutation must prove real columns + transactional engine + lockable transaction.
    require(abilities, "DESCRIBE `", "database real-column discovery")
    require(abilities, "SHOW TABLE STATUS LIKE", "database engine discovery")
    require(abilities, "mad4b_non_transactional_table_denied", "non-transactional DB deny")
    require(abilities, "mad4b_transaction_required", "transaction startup fail-closed")
    require(abilities, "FOR UPDATE", "locked DB mutation preflight")

    # Breakglass requires three gates and bounded SELECT before execution.
    require(policy, "mad4b_mcp_breakglass_permission', false", "independent breakglass approval gate")
    require(abilities, "mad4b_select_limit_required", "raw SELECT execution bound")
    require(abilities, "requested_limit > $max", "raw SELECT max_rows bound")

    # High-side-effect adapters default deny exceptional paths.
    require(bitflows, "mad4b_scp_bitflows_flow_allowed', false", "Bit Flows per-flow default deny")
    require(elementor, "MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED", "Elementor legacy master opt-in")
    require(elementor, "mad4b_scp_allow_elementor_legacy_write', false", "Elementor legacy site-policy deny")
    require(jetengine, "mad4b_scp_jetengine_field_write_allowed', false", "JetEngine unknown-field default deny")
    require(jetengine, "$field !== sanitize_key( $field )", "JetEngine exact-key rejection")

    # MCP annotations must not advertise mutating tools as safe/read-only.
    require(base, "$effective_destructive = $readonly ? (bool) $destructive : true", "adapter destructive annotation enforcement")
    require(abilities, "'destructive' => $readonly ? (bool) $destructive : true", "core destructive annotation enforcement")

    # Audit summaries must preserve bounded structured evidence without leaking obvious credentials.
    require(audit, "SUMMARY_MAX_DEPTH", "bounded audit summary depth")
    require(audit, "SUMMARY_MAX_ITEMS", "bounded audit summary item count")
    require(audit, "sanitize_summary_array", "structured audit summary preservation")
    require(audit, "is_sensitive_summary_key", "audit credential-key redaction")
    require(audit, "[REDACTED]", "audit redaction marker")

    # Arabic/multibyte SEO diagnostics are character-aware when mbstring exists.
    require(seo, "mb_strlen", "Unicode-aware SEO length")
    require(seo, "'UTF-8'", "explicit SEO UTF-8 length")

    # Guard against regression to the exact previously observed unsafe defaults.
    forbid(bitflows, "mad4b_scp_bitflows_flow_allowed', true", "allow-all Bit Flows policy")
    forbid(jetengine, "mad4b_scp_jetengine_field_write_allowed', true", "allow-all JetEngine field policy")
    forbid(policy, "mad4b_mcp_breakglass_permission', true", "implicit breakglass approval")

    print("mad4b.site-control-plane.pre-install-hardening.v1: PASS")

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"mad4b.site-control-plane.pre-install-hardening.v1: FAIL: {exc}", file=sys.stderr)
        raise
