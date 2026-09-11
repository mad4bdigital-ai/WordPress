from pathlib import Path

root = Path(__file__).resolve().parents[1]
admin = (root / 'includes/class-mad4b-scp-approval-decision-admin.php').read_text(encoding='utf-8')
tickets = (root / 'includes/class-mad4b-scp-approval-tickets.php').read_text(encoding='utf-8')
planner = (root / 'includes/class-mad4b-scp-staging-write-planning-guard.php').read_text(encoding='utf-8')
servers = (root / 'includes/class-mad4b-scp-servers.php').read_text(encoding='utf-8')

required_admin = [
    "const CONTRACT = 'mad4b.approval-decision-admin.v1'",
    "add_action( 'admin_post_' . self::ACTION",
    "current_user_can( 'manage_options' )",
    "check_admin_referer( $nonce_action )",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    "'staging' !== (string) $environment",
    "'mutation' !==",
    "'mad4b-write' !==",
    "'mad4b/database-raw-query'",
    "expected_payload_sha256",
    "expected_candidate_sha",
    "expected_build_fingerprint",
    "MAD4B_SCP_Approval_Tickets::candidate_binding",
    "MAD4B_SCP_Approval_Tickets::decide_pending",
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability",
    "MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write'",
    "This page never executes the target mutation",
]
missing = [marker for marker in required_admin if marker not in admin]
if missing:
    raise SystemExit('Missing independent approval-decision invariant: ' + ' | '.join(missing))

forbidden_admin = [
    "wp_register_ability(",
    "mad4b/approval-decision",
    "MAD4B_SCP_Mutation_Manager",
    "call_user_func(",
    "execute_callback",
    "mad4b/database-update",
    "mad4b/filesystem-write",
]
found = [marker for marker in forbidden_admin if marker in admin]
if found:
    raise SystemExit('Human decision console gained target-execution or MCP authority: ' + ' | '.join(found))

required_ticket = [
    "const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v1'",
    "const CANDIDATE_BINDINGS_OPTION = 'mad4b_scp_approval_candidate_bindings_v1'",
    "const MAX_CANDIDATE_BINDINGS = 100",
    "if ( self::exact_governed_staging() )",
    "MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()",
    "update_option( self::CANDIDATE_BINDINGS_OPTION, $bindings, false )",
    "public static function decide_pending",
    "status = 'approved'",
    "status = 'revoked'",
    "status = 'pending' AND payload_sha256 = %s AND expires_at >= %s",
    "MAD4B_SCP_Audit::record( 'mad4b/approval-decision'",
    "require_once __DIR__ . '/class-mad4b-scp-approval-decision-admin.php'",
    "MAD4B_SCP_Approval_Decision_Admin::boot()",
]
missing = [marker for marker in required_ticket if marker not in tickets]
if missing:
    raise SystemExit('Missing atomic approval-ticket decision invariant: ' + ' | '.join(missing))

# A candidate binding failure on exact governed Staging must remove the unusable
# pending ticket rather than leave a ticket which can never be safely decided.
if "$wpdb->delete( $t['approvals']" not in tickets or "mad4b_approval_candidate_binding_failed" not in tickets:
    raise SystemExit('Exact Staging planning does not fail closed when candidate binding persistence fails')

# Preserve the original planner separation: plan creates pending only and never
# auto-approves or executes a target.
for marker in [
    "'creates_pending_ticket_only' => true",
    "'auto_approves' => false",
    "'target_ticket_class' => 'mutation'",
    "'breakglass_target_allowed' => false",
]:
    if marker not in planner:
        raise SystemExit('approval-plan separation regressed: ' + marker)

# The human decision action must stay outside every MCP server/tool projection.
if 'approval-decision' in servers:
    raise SystemExit('Human approval decision leaked into MCP server projection')

print('mad4b.approval-decision.contract.v1: PASS')
