#!/usr/bin/env python3
"""Pure source tests for VPS company discovery and Production capability parity."""
import importlib.util, pathlib
module=pathlib.Path(__file__).resolve().parents[1]/"executor"/"onboarding-v1.py"
spec=importlib.util.spec_from_file_location("company_inventory",module)
inv=importlib.util.module_from_spec(spec);spec.loader.exec_module(inv)
source={"contract":"mad4b.company-environment-cli.v1","environment":"staging",
"central_origin":"https://tours.dev.mad4b.com","registrations":{
"wayfatours:production":{"canonical_origin":"https://wayfatours.mad4b.com",
"site_uuid":"b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b","identity_candidate":False},
"all-royal-egypt:production":{"canonical_origin":"https://www.allroyalegypt.com",
"site_uuid":None,"identity_candidate":True}}}
host={"allow_general_shell":True,"allow_raw_sql":True,"scopes":{
"wayfatours:production":{"allow_operator":True,"allow_sql":True,
"allow_mutation":True,"allow_production_mutation":True}}}
one=inv.project(source,host)
assert one["wayfatours:production"]["native_vps_write_authorized"] is True
assert one["wayfatours:production"]["native_host_authority_state"]=="HOST_NATIVE_OPERATOR_AUTHORIZED_MANAGED_PENDING"
assert one["wayfatours:production"]["onboarding_state"]=="READ_ADAPTER_READY_MANAGED_PENDING"
assert one["all-royal-egypt:production"]["native_vps_write_authorized"] is False
assert one["all-royal-egypt:production"]["native_host_authority_state"]=="PRODUCTION_HOST_AUTHORITY_ENROLLMENT_REQUIRED"
assert one["all-royal-egypt:production"]["onboarding_state"]=="READ_AND_MANAGED_ENROLLMENT_REQUIRED"
assert one["all-royal-egypt:production"]["native_vps_operator_capability_available"] is True
assert all(not x["managed_execution_authorized"] for x in one.values())
assert all(not x["production_mutation"] for x in one.values())
assert inv.project(source,{})["wayfatours:production"]["native_vps_write_authorized"] is False
host["scopes"]["wayfatours:production"]["allow_production_mutation"]=False
assert inv.project(source,host)["wayfatours:production"]["native_vps_write_authorized"] is False
print("PASS 10: inventory parity, backwards-compatible v1 states, exact grant and no synthetic managed authorization")
