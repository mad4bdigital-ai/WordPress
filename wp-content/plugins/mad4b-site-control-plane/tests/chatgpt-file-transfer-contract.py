#!/usr/bin/env python3
"""Static fail-closed contract for the opt-in ChatGPT file transfer REST bridge."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = (root / "includes/class-mad4b-scp-chatgpt-file-transfer.php").read_text()
bootstrap = (root / "mad4b-site-control-plane.php").read_text()
write_catalog = (root / "includes/class-mad4b-scp-server-write-catalog.php").read_text()
chatgpt_servers = (root / "includes/class-mad4b-scp-servers.php").read_text()
checks = {
    "staging_only": "'staging' === sanitize_key" in source,
    "no_anonymous": "is_user_logged_in()" in source,
    "wp_upload_capability": "current_user_can( 'upload_files' )" in source,
    "site_profile": "site_urls_match_enrollment()" in source and "origin_enrolled()" in source,
    "scoped_capability": "const CAPABILITY = 'mad4b_attachment_transfer';" in source and "current_user_can( self::CAPABILITY )" in source,
    "scoped_ability_identity": "const ABILITY = 'mad4b/attachment-transfer';" in source,
    "ability_registered": "wp_register_ability( self::ABILITY" in source,
    "nonpublic_write_ability": "'surface' => 'write'" in source,
    "ability_dispatch_guarded": "if ( ! self::permitted() )" in source,
    "global_write_kill_switch": "MAD4B_SCP_Policy::mutation_gate_status()" in source and "explicit_kill_switch" in source,
    "no_broad_mutation_grant": "MAD4B_SCP_Policy::can_mutate()" not in source,
    "governed_user": "MAD4B_SCP_Policy::can_connect_user" in source,
    "bounded_chunks": "const CHUNK_BYTES = 131072;" in source,
    "bounded_total": "const MAX_BYTES = 16777216;" in source,
    "sha256_readback": "hash_equals( $s['sha256'], hash_file( 'sha256', $path ) )" in source,
    "strict_base64": "base64_decode( $encoded, true )" in source,
    "lock_and_offset": "flock( $f, LOCK_EX | LOCK_NB )" in source and "(int) $stat['size'] !== $offset" in source,
    "mime_check": "wp_check_filetype_and_ext" in source,
    "wordpress_media": "wp_handle_sideload" in source and "wp_insert_attachment" in source,
    "sideload_argument_is_variable": "wp_handle_sideload( $sideload_file, array(" in source and "$sideload_file = array(" in source,
    "bounded_staging_cleanup": "function cleanup_site_ready()" in source and "function cleanup_expired()" in source and "if ( $scanned >= 200 ) break;" in source,
    "portable_temp_root": "realpath( sys_get_temp_dir() )" in source and "realpath( get_temp_dir() )" not in source,
    "not_publish": "'publication_performed' => false" in source,
    "no_remote_fetch": "wp_remote_get" not in source and "curl_" not in source,
    "rest_permission_callback": "'permission_callback' => array( __CLASS__, 'permitted' )" in source,
    "wired": "MAD4B_SCP_ChatGPT_File_Transfer::boot();" in bootstrap,
    "governed_write_catalog": "'mad4b/attachment-transfer'" in write_catalog and "function core_write_candidates()" in write_catalog,
    "no_direct_catalog_bypass": "'mad4b/attachment-transfer'" not in chatgpt_servers,
    "separate_actor_capability": "current_user_can( self::CAPABILITY )" in source,
}
for name, ok in checks.items():
    if not ok:
        raise SystemExit("CHATGPT_TRANSFER_CONTRACT_FAIL:" + name)
print("CHATGPT_TRANSFER_CONTRACT: PASS (%d assertions)" % len(checks))
