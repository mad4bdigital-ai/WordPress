#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
reconciler = (ROOT / 'includes/class-mad4b-scp-live-acceptance-reconciler.php').read_text('utf-8')
portable = (ROOT / 'includes/class-mad4b-scp-portable-snapshot-attestation.php').read_text('utf-8')
external = (ROOT / 'includes/class-mad4b-scp-external-snapshot-finalizer.php').read_text('utf-8')
fence = (ROOT / 'includes/class-mad4b-scp-execution-fence.php').read_text('utf-8')
overrides = (ROOT / 'includes/class-mad4b-scp-governed-ability-overrides.php').read_text('utf-8')


def require(text, marker, label):
    if marker not in text:
        raise SystemExit(f'FAIL {label}: missing {marker!r}')


def forbid(text, marker, label):
    if marker in text:
        raise SystemExit(f'FAIL {label}: forbidden {marker!r}')

for marker in [
    "const CONTRACT = 'mad4b.live-acceptance-reconciler.v1'",
    "MAD4B_SCP_Audit::tail( self::AUDIT_LIMIT )",
    "MAD4B_SCP_Audit::verify_chain()",
    "MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id )",
    "'mad4b_approval_replay_denied'",
    "'mad4b/mutation-undo'",
    "'durable_authoritative_reconstruction'",
    "'mad4b/mutation-get'",
    "'read' => array( 'mad4b/mutation-get' )",
]:
    require(reconciler, marker, 'reconciler-contract')

for marker in [
    "class-mad4b-scp-live-acceptance-reconciler.php",
    "class-mad4b-scp-portable-snapshot-attestation.php",
    "class-mad4b-scp-external-snapshot-finalizer.php",
]:
    require(fence, marker, 'early-load')

for marker in [
    "const CONTRACT = 'mad4b.portable-external-snapshot.v3'",
    "const TOKEN_LEDGER_OPTION = 'mad4b_scp_external_snapshot_export_tokens_v3'",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    "if ( ! self::staging_allowed() ) return;",
    "mad4b_external_snapshot_wrong_target",
    "random_bytes( 32 )",
    "'mad4bext_' . bin2hex( $secret )",
    "'token_digest' => hash( 'sha256', $token )",
    "'snapshot_identity_digest' => hash( 'sha256', $snapshot_token )",
    "public static function validate_external_token(",
    "public static function validate_token_digest(",
    "MAD4B-EXTERNAL-SNAPSHOT-ATTESTATION.json",
    "MAD4B-EXTERNAL-SNAPSHOT-ID.txt",
    "X-MAD4B-External-Snapshot-Token",
    "check_admin_referer( 'mad4b_skill_export'",
]:
    require(portable, marker, 'portable-attestation')

for marker in [
    "const CONTRACT = 'mad4b.external-snapshot-finalizer.v3'",
    "const ATTESTATION_CONTRACT = 'mad4b.external-snapshot-attestation.v3'",
    "const VERIFY_CONTRACT = 'mad4b.external-snapshot-verification.v3'",
    "const HANDSHAKE_TTL = 900",
    "MAD4B_SCP_Portable_Snapshot_Attestation::validate_external_token(",
    "MAD4B_SCP_Portable_Snapshot_Attestation::validate_token_digest(",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "'oauth2_bearer'",
    "'mad4b:read'",
    "'https://chatgpt.com/oauth/client.json'",
    "'mad4b-chatgpt'",
    "'finalizer_subject_binding_verified'",
    "'finalizer_context_digest'",
    "'external_session_binding_changed'",
    "'fresh_post_export_external_context_required'",
    "'$observed + 60 < $minimum'",
    "'snapshot_changed_after_export'",
    "'expected_token_disclosed' => false",
    "'local_snapshot_identity_disclosed' => false",
    "update_option( self::OPTION",
]:
    require(external, marker, 'external-snapshot-finalizer')

# The external mutation evidence surface must remain read-only and bounded.
for marker in [
    "'mad4b/mutation-get'",
    "'readonly' => true",
    "unset( $record['rollback_payload'], $record['rollback_payload_sha256'] )",
]:
    require(overrides, marker, 'bounded-mutation-evidence')

# The strict verifier must not expose either the package secret or its expected value.
for marker in [
    "'live_snapshot_token' =>",
    "'expected_external_token' =>",
    "'external_snapshot_token' => $client",
]:
    forbid(external, marker, 'no-self-certification')

# Persistent records contain digests only. Plaintext export proofs belong only in the ZIP.
for marker in [
    "'external_snapshot_token' => $token",
]:
    forbid(portable.split('private static function persist_record', 1)[-1], marker, 'no-plaintext-persistence')

# No acceptance helper may gain direct raw SQL / Breakglass / Production write authority.
for text, label in [(reconciler, 'reconciler'), (portable, 'portable'), (external, 'external-finalizer')]:
    for forbidden in [
        'mad4b/database-raw-query',
        '$wpdb->',
        'activate_plugin(',
        'deactivate_plugins(',
        'file_put_contents(',
        'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED',
    ]:
        forbid(text, forbidden, f'{label}-fail-closed')

print('mad4b.live-acceptance-reconciler-contract.v3: PASS')
