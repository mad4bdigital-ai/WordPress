from pathlib import Path

root = Path(__file__).resolve().parents[1]
admin = (root / 'includes/class-mad4b-scp-approval-decision-admin.php').read_text(encoding='utf-8')
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
tickets = (root / 'includes/class-mad4b-scp-approval-tickets.php').read_text(encoding='utf-8')
repository = (root / 'includes/class-mad4b-scp-approval-repository.php').read_text(encoding='utf-8')
planner = (root / 'includes/class-mad4b-scp-staging-write-planning-guard.php').read_text(encoding='utf-8')
servers = (root / 'includes/class-mad4b-scp-servers.php').read_text(encoding='utf-8')
handoff = (root / 'includes/adapters/class-mad4b-scp-approval-handoff-adapter.php').read_text(encoding='utf-8')

required_admin = [
    "const CONTRACT = 'mad4b.approval-decision-admin.v1'",
    "add_action( 'admin_post_' . self::ACTION",
    "add_action( 'admin_init', array( __CLASS__, 'protect_read_model_hot_path' ), 0 )",
    "public static function protect_read_model_hot_path()",
    "remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'prime_admin_mcp_runtime' ), 1 )",
    "remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'reconcile_authority_on_mad4b_admin' ), 20 )",
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
    "MAD4B_SCP_Schema::critical_ready()",
    "MAD4B_SCP_Approval_Tickets::candidate_binding_from_ticket",
    "MAD4B_SCP_Approval_Tickets::decide_pending",
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability",
    "MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write'",
    "prepare_authority_runtime_after_validation",
    "rest_get_server()",
    "MAD4B_SCP_MCP_Registration_Rescue::reconcile( 'admin_approval_decision' )",
    "MAD4B_SCP_Staging_Write_Authority::reconcile()",
]
missing = [marker for marker in required_admin if marker not in admin]
if missing:
    raise SystemExit('Missing Approval Console v2 invariant: ' + ' | '.join(missing))

forbidden_admin = [
    "wp_register_ability(", "mad4b/approval-decision", "MAD4B_SCP_Mutation_Manager",
    "call_user_func(", "execute_callback", "mad4b/database-update", "mad4b/filesystem-write",
]
found = [marker for marker in forbidden_admin if marker in admin]
if found:
    raise SystemExit('Human decision console gained target-execution or MCP authority: ' + ' | '.join(found))

# The GET path must remove expensive runtime lifecycle hooks, while POST keeps the
# strict nonce -> physical schema -> exact validation -> runtime -> decision order.
protect_start = admin.index('public static function protect_read_model_hot_path')
protect_end = admin.index('public static function register_page', protect_start)
protect_body = admin[protect_start:protect_end]
for required in [
    "'GET' !== strtoupper",
    "self::PAGE_SLUG !== $page",
    "remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'prime_admin_mcp_runtime' ), 1 )",
    "remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'reconcile_authority_on_mad4b_admin' ), 20 )",
]:
    if required not in protect_body:
        raise SystemExit('Approval GET hot-path guard regressed: ' + required)
for forbidden in ['rest_get_server(', '::reconcile()', 'decide_pending(', 'MAD4B_SCP_Mutation_Manager']:
    if forbidden in protect_body:
        raise SystemExit('Approval GET hot-path guard gained side effects: ' + forbidden)

handler_start = admin.index('public static function handle_admin_post')
handler_end = admin.index('public static function render_page', handler_start)
handler_body = admin[handler_start:handler_end]
if handler_body.index('check_admin_referer( $nonce_action )') > handler_body.index('self::decide( $request )'):
    raise SystemExit('Approval admin-post can enter decision logic before nonce validation')

decide_start = admin.index('public static function decide')
decide_end = admin.index('public static function handle_admin_post', decide_start)
decide_body = admin[decide_start:decide_end]
for first, second, label in [
    ('MAD4B_SCP_Schema::critical_ready()', 'validate_decision_for_test(', 'physical schema guard must precede exact validation'),
    ('validate_decision_for_test(', 'prepare_authority_runtime_after_validation()', 'validation must precede authority runtime preparation'),
    ('prepare_authority_runtime_after_validation()', 'MAD4B_SCP_Staging_Write_Authority::is_write_ability', 'runtime preparation must precede mounted-target recheck'),
    ('MAD4B_SCP_Staging_Write_Authority::is_write_ability', 'MAD4B_SCP_Approval_Tickets::decide_pending', 'target recheck must precede ticket transition'),
]:
    if decide_body.index(first) > decide_body.index(second):
        raise SystemExit('Approval lifecycle ordering regressed: ' + label)

prepare_start = admin.index('private static function prepare_authority_runtime_after_validation')
prepare_end = admin.index('public static function decide', prepare_start)
prepare_body = admin[prepare_start:prepare_end]
for forbidden in ['decide_pending(', 'wp_register_ability(', 'MAD4B_SCP_Mutation_Manager', 'call_user_func(']:
    if forbidden in prepare_body:
        raise SystemExit('Authority runtime preparation gained decision or target-execution authority: ' + forbidden)

# Plugin may continue to classify the route as a bounded MAD4B admin surface for
# shared routing, but the Approval Console GET removes the heavy lifecycle hooks.
for marker in [
    'public static function is_authority_admin_surface()',
    "'mad4b-approval-decisions' === $page",
    'public static function reconcile_authority_on_mad4b_admin()',
    'public static function prime_admin_mcp_runtime()',
]:
    if marker not in plugin:
        raise SystemExit('Approval decision page is missing governed admin routing: ' + marker)

# Schema v5 candidate binding is durable on the approval row. The legacy option
# may remain for rollback/migration compatibility but cannot be the primary save path.
required_ticket = [
    "const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v1'",
    "public static function bind_ticket_to_current_candidate",
    "public static function candidate_binding_from_ticket",
    "'candidate_binding_contract'",
    "'candidate_sha'",
    "'build_fingerprint'",
    "'binding_environment'",
    "'binding_host'",
    "'bound_at'",
    "private static function save_candidate_binding",
    "$wpdb->update(",
    "private static function require_critical_schema()",
    "MAD4B_SCP_Schema::critical_ready()",
    "public static function decide_pending",
    "status = 'approved'",
    "status = 'revoked'",
    "status = 'pending' AND payload_sha256 = %s AND expires_at >= %s",
    "MAD4B_SCP_Audit::record( 'mad4b/approval-decision'",
    "require_once __DIR__ . '/class-mad4b-scp-approval-repository.php'",
    "require_once __DIR__ . '/class-mad4b-scp-approval-decision-admin.php'",
    "MAD4B_SCP_Approval_Decision_Admin::boot()",
]
missing = [marker for marker in required_ticket if marker not in tickets]
if missing:
    raise SystemExit('Missing v5 approval-ticket invariant: ' + ' | '.join(missing))

save_start = tickets.index('private static function save_candidate_binding')
save_end = tickets.index('private static function legacy_candidate_binding', save_start)
save_body = tickets[save_start:save_end]
if '$wpdb->update(' not in save_body:
    raise SystemExit('Candidate binding is not persisted on the approval ticket row')
if 'update_option( self::CANDIDATE_BINDINGS_OPTION' in save_body:
    raise SystemExit('Candidate binding regressed to option-based primary persistence')

create_start = tickets.index('public static function create_pending')
create_end = tickets.index('public static function bind_ticket_to_current_candidate', create_start)
create_body = tickets[create_start:create_end]
if 'current_candidate_binding' in create_body or 'save_candidate_binding' in create_body:
    raise SystemExit('Generic create_pending unexpectedly requires live candidate provenance')

# Read model is bounded, paginated, and derives effective status without writing.
for marker in [
    'final class MAD4B_SCP_Approval_Repository',
    'public static function actionable',
    'public static function history',
    'public static function effective_status',
    "'expired'",
    "'stale'",
]:
    if marker not in repository:
        raise SystemExit('Approval read model invariant missing: ' + marker)
for forbidden in [
    '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete(', 'update_option(',
    'MAD4B_SCP_Staging_Write_Authority::reconcile(', 'rest_get_server(',
]:
    if forbidden in repository:
        raise SystemExit('Approval read model gained a write/runtime side effect: ' + forbidden)

for marker in [
    "const CONTRACT = 'mad4b.staging-write-planning-guard.v2'",
    "isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] )",
    "MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate",
    "'mad4b_candidate_bound_pending_ticket'",
    "'candidate_binding_required_for_remote_plan' => true",
    "'creates_pending_ticket_only' => true",
    "'auto_approves' => false",
    "'target_ticket_class' => 'mutation'",
    "'breakglass_target_allowed' => false",
]:
    if marker not in planner:
        raise SystemExit('Remote approval planner invariant missing: ' + marker)

if "$wpdb->delete( $t['approvals']" not in tickets or "mad4b_approval_candidate_binding_failed" not in tickets:
    raise SystemExit('Remote Staging planning does not fail closed when candidate binding persistence fails')
if 'approval-decision' in servers:
    raise SystemExit('Human approval decision leaked into MCP server projection')

# Handoff remains read-only. It may call candidate_binding(), which now prefers the
# v5 ticket-row binding and only falls back to legacy data during migration.
required_handoff = [
    "const CONTRACT = 'mad4b.approval-decision-handoff.v1'",
    "'mad4b/approval-decision-handoff'",
    "'read' => array( 'mad4b/approval-decision-handoff' )",
    "'content' => array()", "'admin' => array()",
    "'human_action_required' => true", "'decision_exposed' => false",
    "'nonce_exposed' => false", "'target_execution_exposed' => false",
    "MAD4B_SCP_Approval_Decision_Admin::current_candidate()",
    "MAD4B_SCP_Approval_Tickets::candidate_binding",
    "MAD4B_SCP_Approval_Decision_Admin::PAGE_SLUG",
]
missing = [marker for marker in required_handoff if marker not in handoff]
if missing:
    raise SystemExit('Missing read-only approval handoff invariant: ' + ' | '.join(missing))
for forbidden_handoff in [
    'decide_pending(', 'wp_create_nonce(', 'wp_nonce_field(', 'admin_post_',
    'MAD4B_SCP_Mutation_Manager', 'execute_callback', 'mad4b/database-update',
    'mad4b/filesystem-write', "'decision' => 'approve'", 'update_option(', 'delete_option(',
]:
    if forbidden_handoff in handoff:
        raise SystemExit('Approval handoff gained decision or mutation authority: ' + forbidden_handoff)

print('mad4b.approval-decision.contract.v5: PASS')
