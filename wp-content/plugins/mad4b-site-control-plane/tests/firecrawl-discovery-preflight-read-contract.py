#!/usr/bin/env python3
"""Exact, non-authorizing Firecrawl discovery preflight source boundaries."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-firecrawl-discovery-preflight.php').read_text()
assert s.count("wp_register_ability('mad4b/firecrawl-discovery-preflight'")==1
assert "'permission_callback'=>array(__CLASS__,'permitted')" in s
assert "return wp_get_environment_type()==='staging'&&current_user_can('manage_options');" in s
assert "if(!self::permitted())return new WP_Error('mad4b_firecrawl_scope_denied'" in s
assert "'additionalProperties'=>false" in s
assert "'required'=>array('post_id')" in s
assert "'public'=>false,'show_in_rest'=>false" in s
assert "'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)" in s
assert 'MAD4B_SCP_Tour_Workflow_Runtime::evaluate(' in s
for unsafe in ('wp_remote_get(', 'wp_remote_post(', 'wp_insert_post(', 'wp_update_post(', 'file_put_contents('):
 assert unsafe not in s,unsafe
print('FIRECRAWL_DISCOVERY_PREFLIGHT_READ: PASS staging-admin-only non-mutating discovery')
