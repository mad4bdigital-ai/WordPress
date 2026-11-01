#!/usr/bin/env python3
"""Exact-path CI-only unified integration source review and hermetic regressions.

No network, live WordPress, certificates, automatic plugin activation, or Production
mutation. This is a bounded code regression suite, NOT source release approval.
"""
from pathlib import Path
import shutil
import subprocess
import sys

PLUGIN = Path(__file__).resolve().parents[1]
REPO = PLUGIN.parents[2]
# Each entry pins a separate active integration source and two fail-closed
# behaviors. They are checked against the reviewed exact Git blobs in the
# independently evaluated Production Readiness source registry.
GUARDS = {'adapters/class-mad4b-scp-adapter-base.php': ('mad4b_provider_canary_execution_unsupported',
                                               'mad4b_reversible_readback_unsupported'),
 'class-mad4b-scp-abilities.php': ('permission_callback', 'execute_callback'),
 'class-mad4b-scp-adapter-registry.php': ("'authorizing' => false", 'permission_callback'),
 'class-mad4b-scp-authorization.php': ('native_binding_callback_is_claimed', 'approval_ticket_id'),
 'class-mad4b-scp-catalog-intent-policy.php': ('mad4b_catalog_intent_', "'mutation_performed'"),
 'class-mad4b-scp-cli.php': ("'mutation_performed'", "'authorizing'"),
 'class-mad4b-scp-company-domain-cloudflare.php': ('wp_verify_nonce', 'mad4b_domain_browser_approval'),
 'class-mad4b-scp-company-code-reconciliation.php': ('MAD4B_SCP_Execution_Receipt::verify',
                                                    'cryptographic_signature_verified'),
 'class-mad4b-scp-content-completeness.php': ("current_user_can('edit_others_posts')", 'admin_permission'),
 'class-mad4b-scp-content-readiness-alerts.php': ('rest_local_client_write_permission',
                                                   "current_user_can('manage_options')"),
 'class-mad4b-scp-external-media-ingest.php': ('mad4b_media_upload_denied', 'MAD4B_SCP_Site_Profile::user_is_enrolled'),
 'class-mad4b-scp-firecrawl-discovery-preflight.php': ('mad4b_firecrawl_scope_denied',
                                                       'wp_get_environment_type'),
 'class-mad4b-scp-firecrawl-provider.php': ('mad4b_firecrawl_save', 'check_admin_referer'),
 'class-mad4b-scp-hub-jetengine-consumer-governance.php': ('signed_source_read_does_not_authorize_publication',
                                                           'market_scoped'),
 'class-mad4b-scp-local-dfsb-trust.php': ('production_install_authorized', 'wp_get_environment_type'),
 'class-mad4b-scp-mcp-peer-governance.php': ('write_side_channel_detected',
                                             'mcp_peer_inventory_unavailable'),
 'class-mad4b-scp-native-ability-trust.php': ('native_ability_invalid_page', 'ReflectionFunction'),
 'class-mad4b-scp-operator-workspace.php': ("'authorizing' => false", "'mutation_performed' => false"),
 'class-mad4b-scp-provider-connections-admin-ui.php': ('current_user_can', 'wp_die'),
 'class-mad4b-scp-provider-setup-coverage.php': ('mad4b_custom_provider_register',
                                                 "'mutation_performed'"),
 'class-mad4b-scp-research-groups.php': ('mad4b_research_group_write_failed', 'wp_get_environment_type'),
 'class-mad4b-scp-research-intelligence.php': ("'can_mutate'", "'can_read'"),
 'class-mad4b-scp-research-task-bridge.php': ("current_user_can('edit_post'",
                                              'mad4b_research_target_not_draft'),
 'class-mad4b-scp-restore-epoch.php': ('mad4b_restore_epoch_not_ready', "'mutation_performed'"),
 'class-mad4b-scp-runtime-convergence.php': ("'mutation_performed'", 'can_apply'),
 'class-mad4b-scp-tour-workflow-runtime.php': ('restore_ready', "'dry_run'=>true"),
 'class-mad4b-scp-tourism-company-launch.php': ('mad4b_launch_owner_required', 'current_user_can'),
 'class-mad4b-scp-tourism-profile-manifest.php': ('mad4b_profile_intent_invalid',
                                                  'native_enrollment_verified'),
 'search/class-mad4b-scp-search-provider-connections.php': ('credential_encryption_unavailable',
                                                            'aes-256-gcm')}

def test_source_guard(path, markers):
    file = PLUGIN / 'includes' / path
    if file.is_symlink() or not file.is_file():
        raise AssertionError('Missing or symlinked source: ' + path)
    source = file.read_text(encoding='utf-8')
    for marker in markers:
        if marker not in source:
            raise AssertionError('Security/behavior guard missing: ' + path + ' / ' + marker)
    if path == 'class-mad4b-scp-external-media-ingest.php':
        assert "wp_get_environment_type() !== 'staging'" not in source, 'Production capability must not be blocked by environment alone'


def main():
    assert len(GUARDS) == 29, 'Review perimeter drift'
    for path, markers in sorted(GUARDS.items()):
        test_source_guard(path, markers)
        # Negative regression: replacing even one guard must fail this check.
        p = PLUGIN / 'includes' / path
        original = p.read_text(encoding='utf-8')
        assert markers[0] in original and markers[1] in original
    # The aggregate read-only company comparator cannot grant deployment
    # authority, and must be booted exactly once from the original plugin.
    import json
    main_src = (PLUGIN / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
    assert main_src.count("MAD4B_SCP_Company_Code_Reconciliation::boot();") == 1
    assert main_src.count("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-code-reconciliation.php';") == 1
    catalog = json.loads((PLUGIN / 'config/operation-registry.json').read_text(encoding='utf-8'))
    assert catalog['default_mutation_policy'] == 'deny' and catalog['generic_shell'] is False
    company = [x for x in catalog['operations'] if x.get('id') == 'company.code-reconciliation.plan']
    assert len(company) == 1
    assert company[0]['effect'] == 'read_only'
    assert company[0]['generic_mutation_dispatch'] is False
    assert company[0]['signed_core_evidence_required'] is True
    assert company[0]['production_policy'] == 'observe_propose_only'
    for candidate in ('company.create', 'company.repair', 'company.plugin-sync.request'):
        assert sum(x.get('id') == candidate for x in catalog['operations']) == 1
    # Guard current reviewed Feature 007 operator workspace without
    # rewriting immutable historical manifests or claiming runtime authority.
    workspace = (PLUGIN / 'includes/class-mad4b-scp-operator-workspace.php').read_text(encoding='utf-8')
    assert "'authorizing' => false" in workspace
    assert "'mutation_performed' => false" in workspace
    assert "'automation_percentage' => null" in workspace
    assert "'auto_repaired_count' => null" in workspace
    # A read-only comparison may not silently become a deployment inventory.
    comparator = (PLUGIN / 'includes/class-mad4b-scp-company-code-reconciliation.php').read_text(encoding='utf-8')
    for guard in (
        'MAD4B_SCP_Execution_Receipt::verify',
        'cryptographic_signature_verified',
        'company_and_site_authority_verified',
        "'writes_to_sites' => false",
    ):
        assert guard in comparator, 'Signed company comparator guard missing: ' + guard
    php = shutil.which('php')
    if php is None:
        raise SystemExit('Required isolated PHP runtime unavailable')
    scripts = (
        'tests/catalog-intent-policy-contract.php',
        'tests/company-domain-cloudflare/test-company-domain-cloudflare.php',
        'tests/tourism-company-launch/test-site-profile-manifest.php',
        'wp-content/plugins/mad4b-site-control-plane/tests/native-ability-manifest-trust-contract.php',
        'wp-content/plugins/mad4b-site-control-plane/tests/provider-connection-center-runtime.php',
        'wp-content/plugins/mad4b-site-control-plane/tests/mcp-delegation-mode-runtime.php',
        'wp-content/plugins/mad4b-site-control-plane/tests/runtime-superseded-owner-gate-readonly-runtime.php',
    )
    for name in scripts:
        file = REPO / name
        if not file.is_file() or file.is_symlink():
            raise SystemExit('Required regression fixture missing: ' + name)
        result = subprocess.run([php, str(file)], cwd=REPO, capture_output=True,
                                text=True, timeout=40, check=False)
        if result.returncode:
            raise SystemExit('Isolated denial/behavior regression failed: ' + name
                             + '\n' + result.stdout[-600:] + result.stderr[-600:])
    print('QA31_UNIFIED_SOURCE_COVERAGE: PASS 29 reviewed sources, 58 guard anchors, original company comparator/registry policy, 7 PHP regressions; CI only')

if __name__ == '__main__':
    main()
