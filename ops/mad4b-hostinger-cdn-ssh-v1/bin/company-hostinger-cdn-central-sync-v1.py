#!/usr/bin/env python3
"""Explicit central Company Manager projection of local signed-scoped SSH evidence."""
import base64,datetime,json,subprocess,sys
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
RECEIPT=BASE/'reports/company-hostinger-cdn-operation-current.json'
KNOWLEDGE=BASE/'knowledge/hostinger-cdn/hostinger-cdn-ssh-invalidation-v1.json'
CONTAINER='php-wkgtkzta35wkkgouwt6ruljc'
EXPECTED_ORIGIN='https://tours.dev.mad4b.com'
def wp(script):
    args=['docker','exec','-u','www-data',CONTAINER,'wp','eval',script,'--path=/var/www/html','--skip-themes']
    p=subprocess.run(args,capture_output=True,text=True,timeout=35)
    if p.returncode:raise RuntimeError('CENTRAL_WPCLI_OPERATION_FAILED')
    return p.stdout.strip()
def main():
    doc=json.loads(RECEIPT.read_text());kb=json.loads(KNOWLEDGE.read_text())
    assert doc['contract']=='mad4b.company-hostinger-cdn-operation.v1'
    assert doc['operation_id']=='company.cache.hostinger.refresh'
    assert kb['knowledge_id']=='hostinger.cdn.ssh.invalidation'
    age=(datetime.datetime.now(datetime.timezone.utc)-datetime.datetime.fromisoformat(doc['utc'])).total_seconds()
    if not 0<=age<3600:raise RuntimeError('STALE_CENTRAL_RECEIPT_NOT_INGESTED')
    assert len(doc['companies'])<=10
    safe={
      'contract':doc['contract'],'operation_id':doc['operation_id'],
      'state':doc['state'],'utc':doc['utc'],'run_id':doc['run_id'],
      'provider_edge_purge_called':doc['provider_edge_purge_called'],
      'companies':{
        key:{field:val.get(field) for field in ('origin','ssh_readback','edge_state','edge_audit_utc','edge_audit_run_id','critical_contexts')}
        for key,val in doc['companies'].items()
      }
    }
    know={'knowledge_id':kb['knowledge_id'],'problem':kb['problem'],
          'remediation':{'provider_action':kb['remediation']['provider_action']}}
    # Site identity precondition, then update only isolated nonautoload options;
    # no network requests, grants or privileged WordPress-user impersonation.
    for option,value in (
      ('mad4b_company_hostinger_cdn_operation_v1',safe),
      ('mad4b_company_hostinger_cdn_knowledge_v1',know),
    ):
        encoded=base64.b64encode(json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()).decode()
        php=(
          '$home=get_option("home");'
          'if($home!=='+json.dumps(EXPECTED_ORIGIN)+'){throw new RuntimeException("CENTRAL_SITE_SCOPE_MISMATCH");}'
          '$value=json_decode(base64_decode('+json.dumps(encoded)+'),true);'
          'if(!is_array($value)){throw new RuntimeException("INVALID_PROJECTED_JSON");}'
          'update_option('+json.dumps(option)+',$value,false);'
          'echo get_option('+json.dumps(option)+')["'+('run_id' if option.endswith('operation_v1') else 'knowledge_id')+'"];'
        )
        reported=wp(php)
        expected=doc['run_id'] if option.endswith('operation_v1') else kb['knowledge_id']
        if expected not in reported:raise RuntimeError('CENTRAL_OPTION_READBACK_NOT_VERIFIED')
    # Read final display through the actual MU plugin rather than trusting the option.
    answer=wp('echo wp_json_encode(MAD4B_Company_Hostinger_CDN_Operation_V1::status());')
    parsed=json.loads(answer.splitlines()[-1])
    if parsed['run_id']!=doc['run_id'] or parsed['knowledge_id']!=kb['knowledge_id']:
        raise RuntimeError('CENTRAL_MANAGER_VIEW_READBACK_MISMATCH')
    print(json.dumps({'central_company_manager':'Tours.dev Staging',
      'projected_run_id':doc['run_id'],'state':parsed['state'],'companies':len(parsed['companies']),
      'knowledge_id':parsed['knowledge_id'],'native_313_receipt':False}))
if __name__=='__main__':main()
