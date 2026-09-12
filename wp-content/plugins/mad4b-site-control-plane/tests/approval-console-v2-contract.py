from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
schema = (root / 'includes/class-mad4b-scp-schema.php').read_text(encoding='utf-8')
tickets = (root / 'includes/class-mad4b-scp-approval-tickets.php').read_text(encoding='utf-8')
repo = (root / 'includes/class-mad4b-scp-approval-repository.php').read_text(encoding='utf-8')
admin = (root / 'includes/class-mad4b-scp-approval-decision-admin.php').read_text(encoding='utf-8')

required_schema = [
    'const VERSION = 5;',
    'candidate_binding_contract varchar(64)',
    'candidate_sha char(40)',
    'build_fingerprint char(64)',
    'binding_environment varchar(32)',
    'binding_host varchar(191)',
    'bound_at datetime NULL',
    'KEY decision_inbox (status,expires_at,id)',
    'KEY candidate_inbox (candidate_sha,build_fingerprint,status,expires_at)',
    'const INTEGRITY_OPTION',
    'public static function critical_ready()',
    'public static function physical_integrity_status()',
    'migrate_legacy_candidate_bindings',
]
missing = [x for x in required_schema if x not in schema]
if missing:
    raise SystemExit('approval-console-v2 schema contract missing: ' + ' | '.join(missing))

is_ready = re.search(r'public static function is_ready\(\)\s*\{(.*?)\n\t\}', schema, re.S)
if not is_ready:
    raise SystemExit('is_ready() not found')
if 'SHOW TABLES' in is_ready.group(1) or 'SHOW COLUMNS' in is_ready.group(1):
    raise SystemExit('hot-path is_ready() performs physical schema queries')
if 'SHOW TABLES' not in schema or 'SHOW COLUMNS' not in schema:
    raise SystemExit('deep physical schema guard missing')

required_tickets = [
    'MAD4B_SCP_Schema::critical_ready()',
    'candidate_binding_from_ticket',
    "'candidate_binding_contract' =>",
    "'candidate_sha' =>",
    "'build_fingerprint' =>",
    '$wpdb->update(',
    "require_once __DIR__ . '/class-mad4b-scp-approval-repository.php';",
]
missing = [x for x in required_tickets if x not in tickets]
if missing:
    raise SystemExit('approval ticket authority guard missing: ' + ' | '.join(missing))

required_repo = [
    "const CONTRACT = 'mad4b.approval-read-model.v2'",
    'public static function actionable',
    'public static function history',
    "status='pending'",
    "ticket_class='mutation'",
    "server_id='mad4b-write'",
    'candidate_binding_contract=%s',
    'candidate_sha=%s',
    'build_fingerprint=%s',
    "'effective_status'",
    "'expired'",
    "'stale'",
]
missing = [x for x in required_repo if x not in repo]
if missing:
    raise SystemExit('approval read-model contract missing: ' + ' | '.join(missing))

required_admin = [
    'protect_read_model_hot_path',
    "remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'prime_admin_mcp_runtime' ), 1 );",
    "remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'reconcile_authority_on_mad4b_admin' ), 20 );",
    'MAD4B_SCP_Approval_Repository::actionable',
    'MAD4B_SCP_Approval_Repository::history',
    "array( 'actionable', 'history' )",
    'MAD4B_SCP_Schema::critical_ready()',
    'candidate_binding_from_ticket',
]
missing = [x for x in required_admin if x not in admin]
if missing:
    raise SystemExit('approval admin v2 contract missing: ' + ' | '.join(missing))

for forbidden in [
    'ORDER BY a.id DESC LIMIT',
    'MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id )',
    'SELECT a.*,g.slug AS agent_slug',
]:
    if forbidden in admin:
        raise SystemExit('approval admin retained N+1/legacy list path: ' + forbidden)

if 'update_option( self::CANDIDATE_BINDINGS_OPTION' in tickets:
    raise SystemExit('new candidate bindings still persist through option hot path')

print('mad4b.approval-console-v2.contract: PASS')
