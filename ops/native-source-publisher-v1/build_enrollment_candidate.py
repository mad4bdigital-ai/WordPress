#!/usr/bin/env python3
"""Deterministic source-only first-party 313 manifest proposal generator.

Never installs to Original313 registry, updates its index or signs a receipt.
"""
import hashlib
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
CATALOG=Path(__file__).resolve().parent/'catalog'
H=ROOT/'ops/native-source-publisher-v1'
CORE=ROOT/'ops/company-code-release-v1/company_code_release.py'

def sha(path:Path)->str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def build()->dict:
    files={
      'handler':(H/'original_313_host_handler.py',
        'native-source-publisher-v1/original_313_host_handler.py'),
      'verifier':(H/'original_313_independent_readback.py',
        'native-source-publisher-v1/original_313_independent_readback.py'),
      'source':(H/'native_source_publisher.py',
        'native-source-publisher-v1/native_source_publisher.py'),
      'core':(CORE,'company-code-release-v1/company_code_release.py'),
      'evidence':(CATALOG/'evidence-schema.json',
        'dynamic-operation-registry-v1/providers/source.code.release/evidence-schema.json')
    }
    for obj,name in files.values():
        if not obj.is_file() or obj.is_symlink() or len(obj.read_bytes())>2_000_000:
            raise ValueError('SOURCE_CANDIDATE_NOT_SAFE:'+name)
    return {
      'contract':'mad4b.dynamic-operation-provider.v1',
      'operation_id':'source.code.release',
      'provider':'mad4b-source-release',
      'version':1,
      'plan_contract':'mad4b.company-code-release.plan.v1',
      'handler':{'path':files['handler'][1],'sha256':sha(files['handler'][0])},
      'verifier':{'path':files['verifier'][1],'sha256':sha(files['verifier'][0])},
      'evidence_schema':'source_code_release.v1',
      'evidence':{'path':files['evidence'][1],'sha256':sha(files['evidence'][0])},
      'command':{
         'format':'wpcli.argv.json.v1','mode':'plan_sha256',
         'bound_operation_id':'source.code.release',
         'prefix':['mad4b','native-source-publisher','apply'],
         'flag':'--plan_sha256='
      },
      'dependencies':[
        {'path':files['source'][1],'sha256':sha(files['source'][0])},
        {'path':files['core'][1],'sha256':sha(files['core'][0])}
      ]
    }

def main():
    catalog=build()
    file=CATALOG/'manifest.candidate.json'
    file.write_text(json.dumps(catalog,indent=2,sort_keys=True)+'\n')
    print(json.dumps({
      'state':'SOURCE_ONLY_MANIFEST_PINNED__REGISTRY_NOT_INSTALLED',
      'operation_id':'source.code.release',
      'candidate_manifest_sha256':sha(file),
      'source_only':True,'first_party_registry_mutated':False
    },sort_keys=True))
if __name__=='__main__':
    main()
