#!/usr/bin/env python3
"""CI-only staging fixture review and route boundary assertions."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/'includes'
a=(root/'class-mad4b-scp-staging-context-fixtures.php').read_text()
b=(root/'class-mad4b-scp-staging-brand-trial.php').read_text()
assert "wp_get_environment_type()==='staging'" in a
assert "current_user_can('manage_options') || current_user_can('mad4b_manage_company_operations')" in a
assert "if(wp_get_environment_type()!=='staging')return;" in a
for route in ('fixtures','draft-fixtures','consumer-checks'):
 mark="register_rest_route('mad4b/v1','/brand-staging-trial/"+route+"'"
 assert a.count(mark)==1
 part=a.split(mark,1)[1].split(']);',1)[0]
 assert "'methods'=>'GET'" in part and "'permission_callback'=>[__CLASS__,'canRead']" in part
assert a.count("register_rest_route('mad4b/v1',")==3
assert "wp_get_environment_type()==='staging'" in b
assert "current_user_can('manage_options') && current_user_can('activate_plugins')" in b
assert "if(wp_get_environment_type()!=='staging')return;" in b
assert "foreach(array('plan'=>'GET','status'=>'GET','create'=>'POST','restore'=>'POST','rename'=>'POST')" in b
assert b.count("register_rest_route('mad4b/v1','/brand-staging-trial/'.$action")==1
assert "'permission_callback'=>[__CLASS__,'canReview']" in b
print('STAGING_BRAND_FIXTURE_ROUTE_GUARDS: PASS staging-only 8 routes, owner/staff read boundaries')
