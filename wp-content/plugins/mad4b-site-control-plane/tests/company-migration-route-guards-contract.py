#!/usr/bin/env python3
"""CI-only route admission and separation of planning/commit source policy."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/'includes'
specs={
 'class-mad4b-scp-company-migration-decisions.php':{'/company-migration-decisions/plan':'GET','/company-migration-decisions/action':'POST'},
 'class-mad4b-scp-company-tenant-migration.php':{'/company-tenant-migration/plan':'GET','/company-tenant-migration/commit':'POST'},
}
for name,routes in specs.items():
 s=(root/name).read_text();assert s.count("register_rest_route('mad4b/v1',")==2
 assert 'public static function permission()' in s or 'public static function permission(){' in s
 for path,method in routes.items():
  marker="register_rest_route('mad4b/v1','"+path+"'";assert s.count(marker)==1
  section=s.split(marker,1)[1].split('register_rest_route(',1)[0]
  assert "'methods'=>'"+method+"'" in section
  assert "'permission_callback'=>[__CLASS__,'permission']" in section
 assert "['status'=>403]" in s or "403);" in s
if True:
 d=(root/'class-mad4b-scp-company-migration-decisions.php').read_text()
 assert 'WP_ENVIRONMENT_TYPE!==\'staging\'' in d
 assert "empty($s['authority_ready'])" in d and "empty($s['origin_match'])" in d
 t=(root/'class-mad4b-scp-company-tenant-migration.php').read_text()
 assert "current_user_can('manage_options')&&current_user_can('activate_plugins')" in t
print('COMPANY_MIGRATION_REST_GUARDS: PASS 4 routes; identity and owner permissions retained')
