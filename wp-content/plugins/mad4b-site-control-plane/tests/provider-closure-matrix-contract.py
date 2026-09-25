from pathlib import Path

root = Path(__file__).resolve().parents[1]
matrix = (root / 'includes' / 'class-mad4b-scp-provider-closure-matrix.php').read_text(encoding='utf-8')
servers = (root / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
parity = (root / 'includes' / 'class-mad4b-scp-remote-operation-parity.php').read_text(encoding='utf-8')

required = [
    "const CONTRACT = 'mad4b.provider-closure-matrix.v1';",
    "const ABILITY = 'mad4b/provider-closure-matrix';",
    "MAD4B_SCP_Local_OAuth_Server::consent_grant_projection()",
    "MAD4B_SCP_Provider_Compatibility_Certification::inventory()",
    "MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan",
    "'authorizing' => false",
    "'mutation_performed' => false",
    "'auto_activation_allowed' => false",
    "'closure_class'",
    "'next_action'",
    "'evidence_required'",
    "'owner_review_required'",
    "'provider_gated_count'",
]
for marker in required:
    if marker not in matrix:
        raise SystemExit(f'missing provider closure matrix invariant: {marker}')

for forbidden in [
    'update_option(',
    'delete_option(',
    'wp_insert_post(',
    'update_post_meta(',
    'wp_remote_post(',
    'shell_exec(',
    'exec(',
    'system(',
    'passthru(',
    'proc_open(',
    'mad4b/database-raw-query',
]:
    if forbidden in matrix:
        raise SystemExit(f'provider closure matrix must remain read-only: {forbidden}')

if servers.count("'mad4b/provider-closure-matrix'") < 2:
    raise SystemExit('provider closure matrix must be available on both read and ChatGPT surfaces')

if "class-mad4b-scp-provider-closure-matrix.php" not in main:
    raise SystemExit('provider closure matrix runtime is not loaded by the plugin')

for marker in [
    "'staging_candidate_binding' => array(",
    "'remote_ability' => 'mad4b/staging-write-candidate-bind'",
    "'full_staging_authority_convergence' => array(",
    "'remote_ability' => 'mad4b/full-staging-authority-apply'",
    "'human_decision_required' => true",
]:
    if marker not in parity:
        raise SystemExit(f'authority convergence operation is not future-discoverable: {marker}')

print('mad4b.provider-closure-matrix.v1: PASS')
