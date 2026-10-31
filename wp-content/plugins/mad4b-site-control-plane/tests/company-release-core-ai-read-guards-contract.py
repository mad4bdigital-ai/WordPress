#!/usr/bin/env python3
"""Read-only exact-source checks for privileged company release and AI usage endpoints."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/'includes'
a=(root/'class-mad4b-scp-company-release-status.php').read_text()
b=(root/'class-mad4b-scp-core-ai-governance.php').read_text()
assert a.count("register_rest_route('mad4b/v1','/company-release/status'")==1
route=a.split("register_rest_route('mad4b/v1','/company-release/status'",1)[1].split(']);',1)[0]
assert "'methods'=>'GET'" in route
assert "'permission_callback'=>static fn()=>current_user_can('manage_options')" in route
assert "'callback'=>[__CLASS__,'status']" in route
assert "'no_mutation_performed'=>true" in a
assert a.count("'Cache-Control','private, no-store'")>=1
assert b.count("register_rest_route('mad4b/v1','core-ai/usage'")==1
usage=b.split("register_rest_route('mad4b/v1','core-ai/usage'",1)[1].split('public static function status()',1)[0]
assert "'methods'=>'GET'" in usage
assert "'permission_callback'=>static function(){return current_user_can('manage_options');}" in usage
assert "'callback'=>array(__CLASS__,'usage')" in usage
assert "if(!current_user_can('manage_options'))return new WP_Error('mad4b_core_ai_usage_denied'" in b
assert "'no_prompt_content'=>true,'no_keys'=>true" in b
print('RELEASE_AI_READ_GUARDS: PASS owner-only GET, private read and no prompt/keys')
