#!/usr/bin/env python3
"""Nonmutating static contract tests. Runtime auth and freshness tested on Staging."""
from pathlib import Path
import ast
import json
r=Path(__file__).resolve().parent
source=(r/'company-independent-authority-bridge.php').read_text()
operator=(r/'readiness-projection.py').read_text()
example=json.loads((r/'consumer.example.json').read_text())
assert example['contract']=='mad4b.authority.readback-consumer.v1'
assert example['site_host']!='tours.dev.mad4b.com'
assert 'tours.dev.mad4b.com' not in source and 'tours.dev.mad4b.com' not in operator
assert 'mad4b.independent-authority.control-plane-bridge.v1' in source
assert 'mad4b.independent-authority.status-projection.v1' in source
assert '/operational-authority/status' in source
assert '/operational-authority/release-readiness' in source
assert "'methods'=>WP_REST_Server::READABLE" in source
assert "'permission_callback'=>[__CLASS__,'permitted']" in source
assert "is_user_logged_in()" in source
assert "current_user_can('manage_options')" in source
assert "MAD4B_SCP_Policy::can_read()" in source
assert 'projection_stale' in source and 'projection_scope_or_contract_invalid' in source
assert 'authority_mode_or_fallback_invalid' in source
assert "'release_permitted'=>false" in source
assert "'completion_certificate_authorized'=>false" in source
assert "'github_fallback_allowed'=>false" in source
assert "'mutation_performed'=>false" in source
assert "'authorization_executed'=>false" in source
assert "'certificate_issued'=>false" in source
assert "'Cache-Control','private, no-store, no-cache, must-revalidate'" in source
assert "is_link($path)" in source and "filesize($path)>8192" in source
for dangerous in ('wp_insert_post(', 'wp_update_post(', 'update_option(', 'delete_option(',
                  'shell_exec(', 'exec(', 'file_put_contents(', 'wp_remote_post('):
    assert dangerous not in source,dangerous
ast.parse(operator)
assert "SELECT selected_mode,automatic_fallback,live_issuance_enabled,environment" in operator
assert 'FROM mad4b_authority_policy_state' in operator
assert "authority_runtime" in operator
assert "'operational_authorization':False" in operator
assert "'approval_or_permit_issued':False" in operator
assert "'non_authorizing_readback_only':True" in operator
assert "settings['site_host']" in operator
assert "settings['consumer_id']" in operator
for operation in ('UPDATE mad4b_', 'INSERT INTO mad4b_', 'DELETE FROM mad4b_', 'CREATE TABLE ',
                  'DROP TABLE ', 'GRANT ', 'CREATE USER ', 'ALTER TABLE ',
                  'sodium_crypto_sign_', 'openssl_sign(', 'private_key'):
    assert operation not in operator,operation
print('INDEPENDENT_AUTHORITY_BRIDGE_CONTRACT PASS')
