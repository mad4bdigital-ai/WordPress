#!/usr/bin/env python3
"""CI-only guard of three company communications REST provider route surfaces."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]/'includes'
specs={
 'class-mad4b-scp-company-communication.php':{'/admin/company-communication':('GET',),'/admin/company-communication/preview':('POST',)},
 'class-mad4b-scp-company-communication-team.php':{'/admin/company-communication/team':('GET',),'/admin/company-communication/users':('GET',)},
 'class-mad4b-scp-company-sender-policy.php':{'/admin/company-sender-policy':('GET','PATCH'),'/admin/company-sender-policy/preview':('POST',)},
}
for name,routes in specs.items():
    source=(root/name).read_text()
    assert source.count("register_rest_route('mad4b/v1',") == sum(len(v) for v in routes.values()),name
    if 'sender-policy' in name:
        assert "$permission=static fn()=>current_user_can('mad4b_manage_company_operations');" in source
    for path,methods in routes.items():
        marker="register_rest_route('mad4b/v1','"+path+"'"
        assert source.count(marker)==len(methods),(name,path)
        entries=source.split(marker)[1:]
        for method,section in zip(methods,entries):
            section=section.split('register_rest_route(',1)[0]
            assert "'methods'=>'"+method+"'" in section,(name,path,method)
            guard="'permission_callback'=>$permission" if 'sender-policy' in name else "'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')"
            assert guard in section,(name,path,method)
print('COMPANY_COMMUNICATION_REST_GUARDS: PASS 7 permissioned registrations across 3 source files')
