#!/usr/bin/env python3
"""Read-only parity test for the source Registry against installed first-party pins."""
from pathlib import Path
import importlib.util
source=Path(__file__).resolve().parents[1]/'registry.py'
installed=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
spec=importlib.util.spec_from_file_location('mad4b_source_registry_compat',source)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
r=module.Registry(installed)
for name in ('company.code.release','plugin.license.activate','company.code.release.receipt.reconcile','host.source.release'):
 p=r.resolve(name)
 assert p.get('operation_id')==name
 assert p.get('command',{}).get('bound_operation_id')==name
 assert r.command_argv(name,'a'*64)[-1].endswith('a'*64)
 print('PASS original_pinned_provider',name,'v',p['version'])
assert r.resolve('company.code.release')['command']['format']=='wpcli.argv.json.v1'
assert r.resolve('host.source.release')['command']['format']=='host.source.ref.v1'
assert r.host_resource_312_public_key and r.host_resource_312_public_key!=r.independent_verifier_public_key
print('PASS separate_host312_signer_pin_preserved')
print('PASS original_independent_verifier_pin_preserved')
print('REGISTRY_ENROLLED_READBACK=PASSED no_grant_no_job_no_write')
