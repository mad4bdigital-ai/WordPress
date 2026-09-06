#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
admin = (ROOT / 'includes/class-mad4b-scp-admin-ui.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


require(admin, 'final class MAD4B_SCP_Admin_UI', 'admin-class')
require(admin, "add_action( 'admin_menu'", 'admin-menu-hook')
require(admin, 'add_menu_page(', 'admin-menu-page')
require(admin, "'manage_options'", 'admin-capability')
require(admin, 'public static function snapshot', 'bounded-snapshot')
require(admin, 'AGENT_LIMIT = 100', 'agent-bound')
require(admin, 'EVIDENCE_LIMIT = 50', 'evidence-bound')
require(admin, 'MAD4B_SCP_Authorization::authority_status()', 'authority-status')
require(admin, 'MAD4B_SCP_Governance_Abilities::agent_list', 'agent-list-service')
require(admin, 'MAD4B_SCP_Governance_Abilities::agent_effective_access', 'effective-access-service')
require(admin, "$t['approvals']", 'approval-evidence')
require(admin, "$t['mutations']", 'mutation-evidence')
require(admin, 'MAD4B_SCP_Audit::storage_status()', 'audit-status')
require(admin, 'MAD4B_SCP_Audit::tail(', 'audit-tail')
require(admin, 'MAD4B_SCP_Adapter_Registry::instance()->runtime_self_test()', 'runtime-self-test')
require(admin, 'MAD4B_SCP_MCP_Peer_Governance::status()', 'peer-governance')
require(admin, 'Read-only governance and runtime evidence.', 'read-only-disclosure')
require(bootstrap, "class-mad4b-scp-admin-ui.php", 'bootstrap-load')
require(plugin, 'MAD4B_SCP_Admin_UI::boot()', 'plugin-boot')

# This first UI slice is visibility only. No admin mutation primitive may be added here.
for forbidden_write in (
    '$_POST', 'admin_post_', 'check_admin_referer(', 'wp_nonce_field(',
    'MAD4B_SCP_Approval_Tickets::approve(', 'MAD4B_SCP_Approval_Tickets::revoke(',
    'MAD4B_SCP_Agent_Registry::grant_ability(', 'MAD4B_SCP_Mutation_Manager::undo_post_mutation(',
    '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete(',
):
    forbid(admin, forbidden_write, 'admin-ui-read-only')

# Do not surface rollback or identity material through the admin snapshot implementation.
for forbidden_sensitive in (
    'rollback_payload', 'rollback_payload_sha256', 'subject_fingerprint',
    'access_token', 'refresh_token', 'authorization_header', 'password_hash',
):
    forbid(admin, forbidden_sensitive, 'admin-ui-no-sensitive-fields')

print('mad4b.site-control-plane.admin-governance-ui-contract.v1: PASS')
