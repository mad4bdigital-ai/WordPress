#!/usr/bin/env python3
"""Fail-closed source contract for Company Performance REST. Runtime checks are
performed separately against Staging WordPress REST to test permission callbacks.
"""
from pathlib import Path
p=Path(__file__).parent/'company-performance-rest.php'
s=p.read_text()
assert 'final class MAD4B_Company_Performance_REST' in s
assert "'mad4b/v1'" in s
for path in (
    '/company-performance/status', '/company-performance/scan',
    '/company-performance/plan', '/native-abilities',
):
    assert path in s,path
assert s.count("'methods'=>WP_REST_Server::READABLE")==1
assert 'WP_REST_Server::CREATABLE' not in s
assert "add_action('rest_api_init'" in s
assert "'permission_callback'=>[__CLASS__,'permission']" in s
assert "is_user_logged_in()" in s and "current_user_can('manage_options')" in s
assert "MAD4B_SCP_Policy::can_read()" in s
assert "'staging'" in s
assert "OWNER_PIN_VERIFIED" in s and "OWNER_PINNED_READ_SOURCE_VERIFIED" in s
assert "'read_source_eligible'" in s
assert "ability->execute($input)" in s
assert "'mutation_performed'" in s and "'auto_apply_allowed'=>false" in s
assert "'Cache-Control','private, no-store, no-cache, must-revalidate'" in s
assert "'Vary','Authorization, Cookie'" in s
for unsafe in (
    'wp_update_post(', 'wp_insert_post(', 'update_option(', 'delete_option(',
    'register_post_type(', 'wp_remote_post(', 'file_put_contents(', 'exec(',
    'shell_exec(', 'wp_set_current_user(', 'switch_to_user(',
):
    assert unsafe not in s, unsafe
for tenant in ('tours.dev.mad4b.com','35138','26212','25927','25917'):
    assert tenant not in s,tenant
print('Company Performance REST read-only source contract PASS')
