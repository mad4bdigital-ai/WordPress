#!/usr/bin/env python3
"""Source-first nonfinancial Mode Pricing registry reconciliation.

Company identity and operation authority come exclusively from the live original
Company Registry/Execution Resolver. A missing target registry can be *planned*
as source-owned defaults, never silently overwrite tenant pricing settings.
"""
from __future__ import annotations
import argparse,base64,datetime,hashlib,json,os,pathlib,re,subprocess,sys
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-fx-distribution-v1.json'
BINDINGS=ROOT/'policies/company-fx-site-bindings.d'
REPORT_DIR=ROOT/'reports/start-company-reconcile'
UUID=re.compile(r'^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$',re.I)
CODE=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
CANDIDATE_MODES={'tour','activity','extra_trip','cruise_tour','cruise','transport','accommodation'}
STRATEGIES={'rate_occupancy','group_size_tiered','seasonal_occupancy','accommodation_matrix'}
PROBE=r'''$profile=MAD4B_SCP_Site_Profile::status();$pages=[];
foreach((array)jet_engine()->options_pages->data->get_items() as $page){
 $meta=maybe_unserialize($page['meta_fields']??[]);
 if(!is_array($meta))continue;
 $names=array_column($meta,'name');
 if(!in_array('shared_mode_policy_rows',$names,true)||
    !in_array('shared_financial_rules_json',$names,true))continue;
 $args=maybe_unserialize($page['args']??[]);
 $pages[]=['slug'=>$page['slug'],'storage_type'=>$args['storage_type']??null,
   'option_prefix'=>(bool)($args['option_prefix']??false)];
}
$sentinel='__MAD4B_NO_SOURCE_MODE_POLICY__';
$rows=count($pages)===1?get_option($pages[0]['slug'].'_shared_mode_policy_rows',$sentinel):$sentinel;
$available=is_array($rows)?count($rows):0;
$route=isset(rest_get_server()->get_routes()['/shared-product/v1/tour-form-price-preview']);
$native=class_exists('MAD4B_Form6_Readonly_Price_Preview_V1',false);
$nativeStatus=$native?MAD4B_Form6_Readonly_Price_Preview_V1::status():null;
echo wp_json_encode(['site_uuid'=>$profile['site_uuid']??null,
  'origin'=>untrailingslashit(home_url('/')),
  'environment'=>wp_get_environment_type(),
  'ready'=>!empty($profile['configured'])&&!empty($profile['origin_match'])&&
   !empty($profile['deployment_binding_match'])&&!empty($profile['environment_match']),
  'pages'=>$pages,'rows'=>$rows===$sentinel?null:$rows,'mode_count'=>$available,
  'preview_route_registered'=>$route,'presentation_quote_provider'=>$nativeStatus,
  'original_quote_function'=>function_exists('shared_presentation_form6_price_quote'),
  'original_public_price_visual_provider'=>class_exists('MAD4B_Company_Price_Preview_Reconcile_Provider_V1',false)
   ?MAD4B_Company_Price_Preview_Reconcile_Provider_V1::inspect():null,
  'form6_projection_source'=>is_readable(WP_PLUGIN_DIR.'/mad4b-commerce/modules/compat/local-presentation-fluent-projection-v1.php'),
  'form6_projection_loaded'=>in_array(realpath(WP_PLUGIN_DIR.'/mad4b-commerce/modules/compat/local-presentation-fluent-projection-v1.php'),array_map('realpath',get_included_files()),true),
  'selection_asset_source'=>is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-selection-state-v1.js'),
  'price_asset_source'=>is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-fluent-quote-v1.js'),
  'commerce_enabled'=>defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true,
  'presentation_enabled'=>defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')&&MAD4B_PRESENTATION_RUNTIME_ENABLED===true]);'''
CORE=r'''$company=getenv('MAD4B_RECONCILE_COMPANY');$env=getenv('MAD4B_RECONCILE_ENV');
if(!class_exists('MAD4B_Company_Environment_Registry_V2',false)||
 !class_exists('MAD4B_Company_Execution_Resolver_V1',false))WP_CLI::error('ORIGINAL_CENTRAL_RESOLVER_MISSING');
$t=MAD4B_Company_Environment_Registry_V2::target($company,$env);
$p=MAD4B_Company_Execution_Resolver_V1::plan($company,$env,'content.write');
echo wp_json_encode(['target'=>$t,'plan'=>$p,'central_origin'=>untrailingslashit(home_url('/'))]);'''
WRITE=r'''$payload=json_decode(base64_decode(getenv('MAD4B_MODE_POLICY_APPLY')),true);
if(!is_array($payload)||($payload['contract']??'')!=='mad4b.mode-price-policy-apply.v1')
 WP_CLI::error('INVALID_RECONCILE_APPLY_CONTRACT');
$p=MAD4B_SCP_Site_Profile::status();
if(empty($p['configured'])||empty($p['origin_match'])||
 empty($p['deployment_binding_match'])||empty($p['environment_match'])||
 ($p['site_uuid']??null)!==($payload['site_uuid']??null)||
 untrailingslashit(home_url('/'))!==($payload['origin']??null))
 WP_CLI::error('TARGET_ORIGINAL_SITE_IDENTITY_CHANGED');
$pages=[];
foreach((array)jet_engine()->options_pages->data->get_items() as $page){
 $f=maybe_unserialize($page['meta_fields']??[]);$names=is_array($f)?array_column($f,'name'):[];
 if(in_array('shared_financial_rules_json',$names,true)&&in_array('shared_mode_policy_rows',$names,true)){
  $args=maybe_unserialize($page['args']??[]);
  if(($args['storage_type']??null)==='separate'&&!empty($args['option_prefix']))$pages[]=$page['slug'];
 }
}
if(count($pages)!==1||$pages[0]!==($payload['page_slug']??''))
 WP_CLI::error('ORIGINAL_PRICING_PAGE_SCHEMA_DRIFT');
$key=$pages[0].'_shared_mode_policy_rows';
$new=$payload['rows']??null;
if(!is_array($new)||!array_is_list($new)||count($new)<1||count($new)>16)
 WP_CLI::error('SOURCE_MODE_ROW_SHAPE_INVALID');
$check=[];foreach($new as $row){
 if(!is_array($row)||array_keys($row)!==['mode','strategy','enabled','minimum_travelers','maximum_travelers'])
 WP_CLI::error('PRICE_MODE_ALLOWED_FIELDS_ONLY');
 $mode=$row['mode'];$strategy=$row['strategy'];
 if(!is_string($mode)||!preg_match('/^[a-z][a-z0-9_]{1,47}$/D',$mode)||
  !in_array($strategy,['rate_occupancy','group_size_tiered','seasonal_occupancy','accommodation_matrix'],true)||
  !in_array($row['enabled'],['1','0'],true)||
  !is_string($row['minimum_travelers'])||!ctype_digit($row['minimum_travelers'])||
  !is_string($row['maximum_travelers'])||!ctype_digit($row['maximum_travelers'])||
  (int)$row['minimum_travelers']<1||(int)$row['maximum_travelers']>999||
  (int)$row['minimum_travelers']>(int)$row['maximum_travelers']||isset($check[$mode]))
   WP_CLI::error('ORIGINAL_MODE_POLICY_VALIDATION_FAILED');
 $check[$mode]=true;
}
$expected=(string)($payload['sha256']??'');
if(!hash_equals(hash('sha256',wp_json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)), $expected))
 WP_CLI::error('SOURCE_MODE_POLICY_SHA256_MISMATCH');
$missing='__MAD4B_MODE_NO_OPTION__';$current=get_option($key,$missing);
if($current===$new)echo wp_json_encode(['state'=>'UNCHANGED','written'=>false]);
elseif($current!==$missing)WP_CLI::error('TENANT_MODE_POLICY_ALREADY_OWNED_CONFLICT');
elseif(!add_option($key,$new,'',false))WP_CLI::error('MODE_POLICY_INSERT_FAILED');
else{
 $readback=get_option($key,$missing);
 if($readback!==$new)WP_CLI::error('MODE_POLICY_NATIVE_READBACK_FAILED');
 echo wp_json_encode(['state'=>'MODE_POLICY_INSERTED_VERIFIED','written'=>true,'key'=>$key]);
}'''

def run_wp(container,code,extra=None,timeout=85):
    cmd=['docker','exec','-u','33:33']
    for key,value in (extra or {}).items():cmd.extend(['-e',key+'='+value])
    cmd += [container,'/usr/local/bin/wp','--path=/var/www/html','--skip-themes','eval',code]
    res=subprocess.run(cmd,capture_output=True,text=True,timeout=timeout)
    if res.returncode:raise RuntimeError('WP_OPERATION_FAILED:'+res.stderr[-280:])
    candidates=[line for line in res.stdout.splitlines() if line.startswith('{')]
    if len(candidates)!=1:raise RuntimeError('WP_RESPONSE_NOT_UNIQUE_JSON')
    return json.loads(candidates[0])

def valid_target(row):
    required=('company_code','environment','site_uuid','origin','container','document_root')
    if not isinstance(row,dict) or any(not isinstance(row.get(k),str) for k in required):return False
    return bool(CODE.fullmatch(row['company_code']) and row['environment'] in ('staging','production')
         and UUID.fullmatch(row['site_uuid']) and row['origin'].startswith('https://')
         and '/' not in row['origin'][8:] and re.fullmatch('[a-z0-9_.-]{3,70}',row['container'])
         and pathlib.Path(row['document_root']).is_absolute())

def gather_bindings(base):
    result=list(base['targets'])
    if BINDINGS.is_dir():
        for f in sorted(BINDINGS.glob('*.json')):
            stat=f.lstat()
            if f.is_symlink() or stat.st_uid!=0 or stat.st_mode & 0o022:
                raise RuntimeError('UNTRUSTED_BROKER_SITE_BINDING')
            obj=json.loads(f.read_text())
            if obj.get('contract')!='mad4b.company-fx-broker-site-binding.v1':
                raise RuntimeError('BROKER_SITE_BINDING_CONTRACT_UNEXPECTED')
            result.append({k:obj[k] for k in ('company_code','environment','site_uuid','origin','container','document_root')})
    if not all(valid_target(x) for x in result):raise RuntimeError('SITE_BINDING_ENTRY_INVALID')
    ids=[(x['company_code'],x['environment']) for x in result]
    if len(ids)!=len(set(ids)):raise RuntimeError('DUPLICATE_COMPANY_SITE_BINDING')
    return result

def ensure_mount(container,root):
    path=pathlib.Path(root)
    if not path.is_dir() or path.is_symlink() or path.resolve()!=path:
        raise RuntimeError('UNVERIFIED_WP_SITE_ROOT')
    r=subprocess.run(['docker','inspect','-f','{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Source}}{{end}}{{end}}',container],
       capture_output=True,text=True,timeout=15)
    if r.returncode or r.stdout.strip()!=str(path):raise RuntimeError('DOCKER_MOUNT_EXACT_BINDING_FAILED')

def canonical_rows(rows):
    if not isinstance(rows,list) or not 1<=len(rows)<=16:raise RuntimeError('SOURCE_NATIVE_MODE_ROW_SHAPE_INVALID')
    out=[];seen=set()
    for x in rows:
        if not isinstance(x,dict):raise RuntimeError('SOURCE_MODE_ROW_NOT_OBJECT')
        mode=x.get('mode');strategy=x.get('strategy');enabled=x.get('enabled')
        if (not isinstance(mode,str) or mode not in CANDIDATE_MODES or mode in seen
            or strategy not in STRATEGIES or str(enabled) not in ('1','0')):
            raise RuntimeError('SOURCE_MODE_ROW_INVALID')
        try:minimum=int(x.get('minimum_travelers',1));maximum=int(x.get('maximum_travelers',999))
        except (TypeError,ValueError):raise RuntimeError('SOURCE_MODE_BOUNDS_NOT_NUMERIC')
        if not (1<=minimum<=maximum<=999):raise RuntimeError('SOURCE_MODE_BOUNDS_INVALID')
        seen.add(mode)
        out.append({'mode':mode,'strategy':strategy,'enabled':str(enabled),
          'minimum_travelers':str(minimum),'maximum_travelers':str(maximum)})
    if not {'tour','activity','extra_trip','cruise_tour'}.issubset(seen):
        raise RuntimeError('SOURCE_CORE_TOUR_MODES_INCOMPLETE')
    return out

def digest(rows):return hashlib.sha256(json.dumps(rows,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--company',required=True)
    ap.add_argument('--environment',choices=['staging','production'],required=True)
    ap.add_argument('--mode',choices=['plan','verify','apply'],default='plan')
    ap.add_argument('--allow-production',action='store_true',help='Necessary but not sufficient: original central content.write grant still mandatory')
    args=ap.parse_args()
    if not CODE.fullmatch(args.company):raise RuntimeError('COMPANY_ID_INVALID')
    policy=json.loads(POLICY.read_text())
    if policy.get('contract')!='mad4b.company-fx-distribution.v1':raise RuntimeError('SOURCE_CENTRAL_BINDING_POLICY_INVALID')
    origin=policy['source'];ensure_mount(origin['container'],origin['document_root'])
    src=run_wp(origin['container'],PROBE)
    if not(src['ready'] and src['origin']==origin['origin'] and src['site_uuid']==origin['site_uuid']):
        raise RuntimeError('ORIGINAL_SOURCE_SITE_NOT_ATTESTED')
    if len(src['pages'])!=1 or src['pages'][0]['storage_type']!='separate' or not src['pages'][0]['option_prefix']:
        raise RuntimeError('SOURCE_JETENGINE_NATIVE_OPTION_SCHEMA_MISSING')
    source_rows=canonical_rows(src['rows']);sha=digest(source_rows)
    candidates=[x for x in gather_bindings(policy) if x['company_code']==args.company and x['environment']==args.environment]
    if len(candidates)!=1:raise RuntimeError('ORIGINAL_BROKER_TARGET_BINDING_NOT_ENROLLED')
    site=candidates[0];ensure_mount(site['container'],site['document_root'])
    core=run_wp(origin['container'],CORE,{'MAD4B_RECONCILE_COMPANY':args.company,'MAD4B_RECONCILE_ENV':args.environment})
    registered=core.get('target');plan=core.get('plan')
    if (not isinstance(registered,dict) or not isinstance(plan,dict) or core['central_origin']!=origin['origin'] or
        registered.get('site_uuid')!=site['site_uuid'] or registered.get('canonical_origin')!=site['origin'] or
        registered.get('environment')!=site['environment']):
        raise RuntimeError('ORIGINAL_COMPANY_REGISTRY_TARGET_BINDING_MISMATCH')
    target=run_wp(site['container'],PROBE)
    if not (target['ready'] and target['origin']==site['origin'] and
            target['site_uuid']==site['site_uuid'] and target['environment']==site['environment']
            and len(target['pages'])==1 and target['pages'][0]==src['pages'][0]):
        raise RuntimeError('COMPANY_NATIVE_MODE_POLICY_SITE_OR_SCHEMA_MISMATCH')
    original=target['rows'];normalized=canonical_rows(original) if isinstance(original,list) else None
    existing=original is not None
    changed=not existing
    # Tenant display order is intentionally company-owned. Functional mode
    # policy parity compares mode-specific rules, not their UI row order.
    # canonical_rows() has already enforced unique mode keys and valid bounds.
    conflict=existing and sorted(normalized,key=lambda x:x['mode'])!=sorted(source_rows,key=lambda x:x['mode'])
    tenant_row_order_customized=existing and not conflict and normalized!=source_rows
    mode_ready='tour' in {x['mode'] for x in (normalized or [])}
    public_visual=target.get('original_public_price_visual_provider') or {}
    visual_ready=(public_visual.get('public_price_shortcode_source_owner_native') is True
        and public_visual.get('responsive_design_source_provider_loaded') is True)
    frontend_ready=(target['preview_route_registered'] and target['form6_projection_source']
        and target['form6_projection_loaded']
        and target['selection_asset_source'] and target['price_asset_source']
        and (target['original_quote_function'] or
             (target['presentation_quote_provider'] and
              target['presentation_quote_provider'].get('all_readonly_dependencies') is True)))
    report={'contract':'mad4b.company-mode-price-policy-reconcile.v1',
      'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
      'company':args.company,'environment':args.environment,
      'source_origin':src['origin'],'source_uuid':src['site_uuid'],
      'target_origin':target['origin'],'target_uuid':target['site_uuid'],
      'source_mode_rows_count':len(source_rows),'source_template_sha256':sha,
      'source_strategy_registry_verified':True,
      'target_native_option_page':target['pages'][0]['slug'],
      'target_policy_state':'MISSING' if not existing else 'CONFLICT' if conflict else 'MATCHED',
      'target_row_order_customized':bool(tenant_row_order_customized),
      'source_mode_rules_same_as_target':bool(existing and not conflict),
      'target_preview_route_registered':target['preview_route_registered'],
      'target_price_projection_code_present':target['form6_projection_source'],
      'target_price_projection_loaded':target['form6_projection_loaded'],
      'target_frontend_dependencies_present':frontend_ready,
      'target_public_price_visual_provider_ready':visual_ready,
      'target_public_price_shortcode_owner_native':public_visual.get('public_price_shortcode_source_owner_native'),
      'target_responsive_source_css_provider_loaded':public_visual.get('responsive_design_source_provider_loaded'),
      'browser_computed_css_receipt_required':True,
      'required_repair_actions':(
          (['RECONCILE_SOURCE_OWNED_MODE_STRATEGY_SCHEMA'] if changed else []) +
          (['REVIEW_EXISTING_TENANT_MODE_POLICY_CONFLICT'] if conflict else []) +
          (['RECONCILE_SOURCE_PROJECTION_PROVIDER_LOAD'] if not target['form6_projection_loaded'] else []) +
          (['RECONCILE_SOURCE_NATIVE_PUBLIC_PRICE_SHORTCODE'] if not public_visual.get('public_price_shortcode_source_owner_native') else []) +
          (['RECONCILE_SOURCE_RESPONSIVE_CSS_RUNTIME'] if not public_visual.get('responsive_design_source_provider_loaded') else []) +
          (['RECONCILE_PRICE_PREVIEW_ROUTE'] if not target['preview_route_registered'] else []) +
          (['RECONCILE_FRONTEND_ASSET_DEPENDENCIES'] if not target['selection_asset_source'] or not target['price_asset_source'] else [])
      ),
      'central_original_execution_state':plan.get('state'),
      'central_original_execution_allowed':plan.get('execution_allowed') is True,
      'action':'NONE','financial_orders_changed':False,'checkout_enabled':False,
      'target_mutated':False,'ready_for_public_preview':bool(mode_ready and frontend_ready and visual_ready and not conflict)}
    authorized=all([plan.get('state')=='READY_FOR_EXACT_NATIVE_WRITE',
      plan.get('selected_channel')=='native_company_wordpress_write',
      plan.get('execution_allowed') is True,plan.get('remote_write_permitted') is True,
      plan.get('readback_required') is True,plan.get('site_uuid')==site['site_uuid'],
      plan.get('canonical_origin')==site['origin']])
    report['native_execution_authorized']=authorized
    if args.mode=='plan':report['state']='SOURCE_MODE_POLICY_PLAN_MISSING' if changed else 'TARGET_MODE_POLICY_CONFLICT' if conflict else 'SOURCE_MODE_POLICY_MATCHED'
    elif args.mode=='verify':report['state']='SOURCE_MODE_POLICY_VERIFIED' if not changed and not conflict and frontend_ready else 'SOURCE_MODE_POLICY_READBACK_HOLD'
    else:
        if not authorized:raise RuntimeError('ORIGINAL_COMPANY_NATIVE_WRITE_AUTHORITY_NOT_GRANTED')
        if args.environment=='production' and not args.allow_production:raise RuntimeError('EXPLICIT_PRODUCTION_APPLY_FLAG_REQUIRED')
        if conflict:raise RuntimeError('EXISTING_COMPANY_PRICE_POLICY_MUST_NOT_BE_OVERWRITTEN')
        if not frontend_ready:raise RuntimeError('TENANT_PREVIEW_PROVIDER_MISSING_RELEASE_RECONCILIATION_REQUIRED')
        stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        backup=ROOT/'backups'/('native-mode-policy-'+args.company+'-'+stamp)
        backup.mkdir(mode=0o700)
        (backup/'original.json').write_text(json.dumps({'target':target,'source_template':source_rows,'original_plan':plan},ensure_ascii=False,indent=2))
        (backup/'original.json').chmod(0o600)
        payload={'contract':'mad4b.mode-price-policy-apply.v1','site_uuid':target['site_uuid'],
          'origin':target['origin'],'page_slug':target['pages'][0]['slug'],
          'rows':source_rows,'sha256':hashlib.sha256(json.dumps(source_rows,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()}
        v=base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode()
        result=run_wp(site['container'],WRITE,{'MAD4B_MODE_POLICY_APPLY':v})
        after=run_wp(site['container'],PROBE)
        if after['rows']!=source_rows or after['site_uuid']!=target['site_uuid']:
            raise RuntimeError('POST_APPLY_NATIVE_READBACK_MISMATCH_MANUAL_ROLLBACK_REQUIRED:'+str(backup))
        report.update({'state':'SOURCE_MODE_POLICY_APPLIED_VERIFIED','action':result['state'],'target_mutated':result.get('written') is True,
            'backup':str(backup),'ready_for_public_preview':bool(frontend_ready and visual_ready)})
    REPORT_DIR.mkdir(exist_ok=True)
    dest=REPORT_DIR/('mode-price-preview-'+args.company+'-'+args.environment+'.json')
    dest.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))
    return 0 if args.mode=='plan' or report['ready_for_public_preview'] else 2
if __name__=='__main__':
    try:sys.exit(main())
    except (RuntimeError,ValueError,OSError,subprocess.SubprocessError) as e:
        print(json.dumps({'state':'HOLD','reason':str(e)[:350],'orders_mutated':False,'external_write_executed':False}))
        sys.exit(3)
