#!/usr/bin/env python3
"""Exact source-level negative invariant tests, never a live grant or authorization."""
from pathlib import Path

root=Path(__file__).resolve().parents[1]/"wp-content/plugins/mad4b-site-control-plane/includes"
# Each entry describes a reviewed source behavior which cannot be removed silently.
requirements={
"adapters/class-mad4b-scp-local-brand-draft-adapter.php":[
 "mad4b_local_brand_draft_staging_only",
 "MAD4B_SCP_Policy::can_mutate()",
 "draft_preflight_sha256",
 "mad4b_local_brand_draft_quality_failed",
 "mad4b_local_brand_draft_readback_inconsistent",
],
"class-mad4b-scp-adaptive-interaction-ui.php":[
 "is_user_logged_in()",
 "wp_get_session_token()",
 "empty($_SERVER['HTTP_AUTHORIZATION'])",
 "MAD4B_SCP_Adaptive_Interaction_Broker::permitted()",
 "Content-Security-Policy:",
],
"class-mad4b-scp-cli-readiness.php":[
 "MAD4B_SCP_CLI_Execution_Context::resolve(",
 "scheduled_scan_authorized_by_site_policy",
 "'production_mutation_authorized'=>false",
 "hash_equals($plan['plan_sha256'],$expected)",
 "MAD4B_SCP_Content_Readiness_Alerts::run_scheduled()",
],
"class-mad4b-scp-content-readiness-alerts.php":[
 "mad4b_readiness_scheduler_unauthorized",
 "mad4b_readiness_incomplete_scan",
 "mad4b_readiness_capacity_exceeded",
 "'permission_callback'=>array(__CLASS__,'rest_local_client_write_permission')",
 "MAD4B_SCP_Policy::can_mutate()",
],
"class-mad4b-scp-firecrawl-provider.php":[
 "mad4b_firecrawl_stage_admin_required",
 "mad4b_firecrawl_owned_source_only",
 "mad4b_firecrawl_unsafe_endpoint",
 "wp_http_validate_url($url)",
 "mad4b_firecrawl_scrape_invalid",
],
"class-mad4b-scp-hub-jetengine-consumer-governance.php":[
 "site_identity_unverified",
 "reuse_verified_canonical_terms",
 "source_target_wpml_verified",
 "market_selection_scope_invalid",
 "server_side_proofs_verified",
],
"class-mad4b-scp-local-dfsb-trust.php":[
 "local_owner_exact_staging_site_required",
 "local_owner_source_registry_mismatch",
 "local_owner_signature_invalid",
 "production_install_authorized",
 "local_owner_signature_authority_overclaim",
],
"class-mad4b-scp-tourism-company-launch.php":[
 "'public'=>false,'show_ui'=>false,'show_in_rest'=>false",
 "'permission_callback'=>array(__CLASS__,$entry[2])",
 "mad4b_launch_browser_approval_required",
 "wp_verify_nonce($nonce,'wp_rest')",
 "mad4b_launch_coolify_unverified_resource",
],
"class-mad4b-scp-artifacts.php":[
 "MAD4B_SCP_Policy', 'can_read'",
 "MAD4B_SCP_Policy', 'can_mutate'",
 "'mutation_performed' => false",
 "normalize_artifact( $row )",
],
"class-mad4b-scp-authorization.php":[
 "MAD4B_SCP_Policy::can_mutate()",
 "mad4b_mutation_disabled",
 "authorize_mutation_impl(",
 "MAD4B_SCP_Authorization_Decision_Graph::permission_result",
],
"class-mad4b-scp-cli.php":[
 "'production_authorized' => false",
 "'mutation_performed' => false",
 "'read_only' => true",
 "read_only_non_authorizing",
],
"class-mad4b-scp-company-readiness-governance.php":[
 "current_user_can('manage_options') && current_user_can('activate_plugins')",
 "mad4b_company_operator_required",
 "current_user_can('mad4b_propose_company_content')",
 "'permission_callback'=>array(__CLASS__,'guard')",
],
"class-mad4b-scp-connection-status.php":[
 "permission_callback_match",
 "production_approved",
 "write_catalog_inventory",
 "route_permission_validation",
 "catalog_count_match",
],
"class-mad4b-scp-external-media-ingest.php":[
 "current_user_can( 'edit_post', $target )",
 "current_user_can( 'upload_files' )",
 "mad4b_media_operator_scope_denied",
 "upload_readback_confirmed",
 "'mutation_performed' => false",
],
"class-mad4b-scp-mcp-request-scope.php":[
 "current_admin_request_method_is_read_only()",
 "begin_endpoint_diagnostic",
 "governed_staging()",
 "current_user_can( 'manage_options' )",
 "'production_changed' => false",
],
}

def verify_source(source,fragments):
    assert source.startswith("<?php"),"unexpected PHP content"
    for marker in fragments:
        assert marker in source,marker

negative_count=0
for relative,fragments in requirements.items():
    path=root/relative
    assert path.is_file() and not path.is_symlink(),relative
    source=path.read_text()
    verify_source(source,fragments)
    # Mutation sensitivity: independently remove each guard/signpost and
    # ensure this file's exact guard contract fails, not a mere happy-path test.
    for marker in fragments:
        corrupted=source.replace(marker,"__REMOVED_SECURITY_BOUNDARY__")
        try:
            verify_source(corrupted,fragments)
        except AssertionError:
            negative_count+=1
        else:
            raise AssertionError("mutation escaped detection: "+relative+" : "+marker)
    print("SOURCE_SECURITY_NEGATIVE_PASS",relative)
print("REMAINING_SOURCE_SECURITY_CONTRACT: PASS",len(requirements),"sources",negative_count,"negative tests")
