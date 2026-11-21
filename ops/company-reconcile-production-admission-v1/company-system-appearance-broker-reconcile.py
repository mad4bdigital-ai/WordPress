#!/usr/bin/env python3
"""Original Company Reconcile — source-owned login/admin/maintenance presentation.

Uses the original Company Registry + Broker bindings. Dynamic Elementor Kit,
locale and site identity are *read from each tenant*, never cloned from source.
Plan/verify never write. Apply uses exact native content.write authority only.
No user, role, password, pricing, checkout, SMTP or WPML option mutation.
"""
from __future__ import annotations
import argparse,datetime as dt,hashlib,json,os,pathlib,re,runpy,shutil,subprocess,sys,tempfile,time
BASE=pathlib.Path('/opt/mad4b-company-provisioning')
POLICY=BASE/'policies/company-fx-distribution-v1.json'
_original_run_wp=None
resolve_container=None
gather_bindings=None
ensure_mount=None
CORE=None

def load_host_read_dependencies():
    """Use original protected Host providers only for plan/verify, after Apply refusal."""
    global _original_run_wp,resolve_container,gather_bindings,ensure_mount,CORE
    from central_staging_container import resolve_container as original_resolve_container
    original=runpy.run_path(str(BASE/'bin/company-mode-price-policy-reconcile.py'),
                            run_name='mad4b_appearance_common')
    resolve_container=original_resolve_container
    _original_run_wp=original['run_wp']
    gather_bindings=original['gather_bindings']
    ensure_mount=original['ensure_mount']
    CORE=original['CORE']
def run_wp(container, code, extra=None, timeout=85):
    # WP-CLI's existing two-slot guard remains authoritative.
    # Retry only transient capacity denials. No fallback to shell or another site.
    for attempt in range(2):
        try:
            return _original_run_wp(container, code, extra, timeout)
        except RuntimeError as exc:
            if 'STAGING_WPCLI_BUSY' not in str(exc) or attempt:
                raise
            time.sleep(2)

ASSETS=('wp-content/plugins/mad4b-commerce/modules/presentation/company-system-appearance-v1.php',
        'wp-content/plugins/mad4b-commerce/modules/presentation/assets/company-system-appearance-v1.css',
        'wp-content/plugins/mad4b-commerce/modules/presentation/assets/company-admin-notice-accordion-v1.js',
        'wp-content/maintenance.php',
        'wp-content/plugins/mad4b-commerce/modules/presentation/assets/company-admin-tables-v1.js',
        'wp-content/plugins/mad4b-commerce/modules/presentation/company-admin-experience-v1.php',
        'wp-content/plugins/mad4b-commerce/modules/presentation/company-admin-ui-translation-contentjobs-v1.php',
        'wp-content/plugins/mad4b-commerce/modules/presentation/assets/company-admin-theme-toggle-v1.js',
        'wp-content/plugins/mad4b-commerce/modules/presentation/assets/company-admin-contrast-auditor-v1.js',
        'wp-content/plugins/mad4b-commerce/modules/presentation/company-admin-ui-auto-scan-v1.php',
        'wp-content/plugins/mad4b-commerce/modules/presentation/assets/company-admin-ui-auto-scan-v1.js')
SNAPSHOT='wp-content/mad4b-company-appearance-public.json'
BROKER_REPORT=BASE/'reports/start-company-reconcile'
SOURCE_CANON=BASE/'source-owned/system-appearance/maintenance.php'
PROBE=r'''$s=MAD4B_SCP_Site_Profile::status();$origin=untrailingslashit(home_url('/'));
$name=class_exists('MAD4B_Company_System_Appearance_V1',false)?get_bloginfo('name'):get_bloginfo('name');
$name=mb_substr(trim(wp_strip_all_tags((string)$name)),0,72);
$id=(int)get_theme_mod('custom_logo');if(!$id)$id=(int)get_option('site_logo');
$logo=$id?wp_get_attachment_image_url($id,'full'):'';if(!$logo)$logo=get_site_icon_url(192);
$host=wp_parse_url($origin,PHP_URL_HOST);
if(!is_string($logo)||wp_parse_url($logo,PHP_URL_HOST)!==$host||wp_parse_url($logo,PHP_URL_SCHEME)!=='https')$logo='';
$readiness=class_exists('MAD4B_Company_System_Appearance_V1',false)?MAD4B_Company_System_Appearance_V1::reconcile_readiness():null;
$adminScripts=class_exists('MAD4B_Company_System_Appearance_V1',false)?MAD4B_Company_System_Appearance_V1::inspect():null;
echo wp_json_encode(['origin'=>$origin,'site_uuid'=>$s['site_uuid']??null,
 'environment'=>wp_get_environment_type(),'name'=>$name,'logo_url'=>$logo,
 'identity_ready'=>!empty($s['configured'])&&!empty($s['origin_match'])&&
  !empty($s['deployment_binding_match'])&&!empty($s['environment_match']),
 'readiness'=>$readiness,'runtime'=>$adminScripts,
 'bootstrap_includes_provider'=>strpos((string)@file_get_contents(WP_PLUGIN_DIR.'/mad4b-commerce/mad4b-commerce.php'),
  "modules/presentation/company-system-appearance-v1.php")!==false]);'''

def enrolled_runtime(site):
    """Discover a live writable PHP runtime by exact host mount + WP identity.

    The Broker source policy declares the canonical site identity and host
    document root; Docker container names are ephemeral deployment details.
    No fallback to a different site, arbitrary mount or maintenance container.
    """
    root=pathlib.Path(site['document_root']).resolve(strict=True)
    if str(root)!=site['document_root'] or root.is_symlink():
        raise RuntimeError('CANONICAL_ENROLLED_SITE_ROOT_REQUIRED')
    proc=subprocess.run(['docker','ps','--format','{{.ID}}'],capture_output=True,text=True,timeout=15,check=True)
    ids=[x.strip() for x in proc.stdout.splitlines() if re.fullmatch('[a-f0-9]{12,64}',x.strip())]
    if not ids or len(ids)>100:raise RuntimeError('LIVE_DOCKER_RUNTIME_INVENTORY_INVALID')
    process=subprocess.run(['docker','inspect',*ids],capture_output=True,text=True,timeout=18,check=True)
    candidates=[]
    for data in json.loads(process.stdout):
        mounts=data.get('Mounts') or []
        valid_mount=any(m.get('Destination')=='/var/www/html'
           and m.get('Source')==str(root) and m.get('RW') is True for m in mounts)
        if not valid_mount or data.get('State',{}).get('Running') is not True:continue
        name=str(data.get('Name','')).lstrip('/')
        if not re.fullmatch('[A-Za-z0-9_.-]{3,110}',name):continue
        check=subprocess.run(['docker','exec','-u','33:33',name,
          '/bin/sh','-c','test -x /usr/local/bin/wp'],capture_output=True,text=True,timeout=10)
        if check.returncode:continue
        try:
            identity=run_wp(name,'''$s=MAD4B_SCP_Site_Profile::status();echo wp_json_encode([
             'origin'=>untrailingslashit(home_url('/')),
             'site_uuid'=>$s['site_uuid']??null,
             'environment'=>wp_get_environment_type(),
             'identity_ready'=>!empty($s['configured'])&&!empty($s['origin_match'])
                 &&!empty($s['deployment_binding_match'])&&!empty($s['environment_match'])]);''',timeout=30)
        except (RuntimeError,subprocess.TimeoutExpired) as exc:
            if 'STAGING_WPCLI_BUSY' in str(exc):raise
            continue
        if (identity.get('origin')==site['origin']
            and identity.get('site_uuid')==site['site_uuid']
            and identity.get('environment')==site['environment']
            and identity.get('identity_ready') is True):candidates.append(name)
    if len(candidates)!=1:raise RuntimeError('EXACT_LIVE_WORDPRESS_PHP_RUNTIME_NOT_UNIQUE_OR_MISSING')
    selected=dict(site);selected['container']=candidates[0]
    selected['runtime_origin']='docker_live_mount_plus_wp_site_profile'
    return selected

def file_sha(path):return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None
def expected_config(target):
    info=target.get('readiness') or {};colors=info.get('palette') or {}
    keys=('primary','secondary','accent','warm')
    if not all(re.fullmatch('#[a-fA-F0-9]{6}',str(colors.get(k,''))) for k in keys):
        raise RuntimeError('SITE_NATIVE_ELEMENTOR_KIT_PALETTE_UNAVAILABLE')
    name=target.get('name')
    if not isinstance(name,str) or not (1<=len(name)<=72):raise RuntimeError('COMPANY_NAME_NOT_SOURCE_ATTESTED')
    return {'contract':'mad4b.company-appearance-public.v1',
      'site_name':name,'site_origin':target['origin'],
      'logo_url':target['logo_url'],'kit_id':info['active_elementor_kit'],
      **{k:colors[k] for k in keys}}

def inspect_json(path):
    if not path.is_file() or path.stat().st_size>8192:return None
    try:return json.loads(path.read_text())
    except (json.JSONDecodeError,UnicodeError):return None

def report_file(company,environment):return BROKER_REPORT/('system-appearance-'+company+'-'+environment+'.json')
def atomic_json(path,record):
    path.parent.mkdir(mode=0o750,parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.appearance-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as fp:
            json.dump(record,fp,ensure_ascii=False,indent=2)
            fp.write('\n');fp.flush();os.fsync(fp.fileno())
        os.chmod(temp,0o600)
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--company',required=True)
    ap.add_argument('--environment',choices=('staging','production'),required=True)
    ap.add_argument('--mode',choices=('plan','verify','apply'),default='plan')
    ap.add_argument('--allow-production',action='store_true')
    args=ap.parse_args()
    # Legacy Broker plan/verify is NOT an original claimed Native executor.
    # A generic resolver-ready flag and --allow-production must never
    # authorize direct file replacement. Signed Original305 + claimed 306 +
    # independent 312 + enrolled 313 handler is required in a separate lane.
    if args.mode=='apply':
        raise RuntimeError('DIRECT_APPEARANCE_APPLY_DISABLED_SIGNED_ORIGINAL_305_306_312_313_REQUIRED')
    if not re.fullmatch('[a-z][a-z0-9-]{2,47}',args.company):
        raise RuntimeError('COMPANY_CODE_INVALID')
    load_host_read_dependencies()
    policy=json.loads(POLICY.read_text())
    if policy.get('contract')!='mad4b.company-fx-distribution.v1':raise RuntimeError('BROKER_SOURCE_CONTRACT_INVALID')
    # The enrolled source identity is stable; the Docker PHP container is not.
    # Preserve the same exact-mount verification after resolving its live name.
    source=dict(policy['source'])
    source['container']=resolve_container()
    ensure_mount(source['container'],source['document_root'])
    choices=[s for s in gather_bindings(policy)
        if s['company_code']==args.company and s['environment']==args.environment]
    if len(choices)!=1:raise RuntimeError('COMPANY_BROKER_BINDING_REQUIRED')
    site=enrolled_runtime(choices[0]);ensure_mount(site['container'],site['document_root'])
    central=run_wp(source['container'],CORE,{'MAD4B_RECONCILE_COMPANY':args.company,
                                                'MAD4B_RECONCILE_ENV':args.environment})
    registered=central.get('target');plan=central.get('plan')
    if (central.get('central_origin')!=source['origin'] or not isinstance(registered,dict) or
        not isinstance(plan,dict) or registered.get('site_uuid')!=site['site_uuid'] or
        registered.get('canonical_origin')!=site['origin'] or
        registered.get('environment')!=site['environment']):
        raise RuntimeError('ORIGINAL_CENTRAL_REGISTRY_TARGET_MISMATCH')
    src=run_wp(source['container'],PROBE)
    tgt=run_wp(site['container'],PROBE)
    if (not src['identity_ready'] or src['origin']!=source['origin'] or
        src['site_uuid']!=source['site_uuid'] or not src['readiness'] or
        src['readiness']['state']!='SOURCE_SYSTEM_APPEARANCE_READBACK_PASS'):
        raise RuntimeError('LIVE_SOURCE_APPEARANCE_PROVIDER_NOT_ATTESTED')
    if (not tgt['identity_ready'] or tgt['origin']!=site['origin'] or
        tgt['site_uuid']!=site['site_uuid'] or tgt['environment']!=site['environment']):
        raise RuntimeError('TENANT_ORIGINAL_SITE_IDENTITY_NOT_ATTESTED')
    srcroot=pathlib.Path(source['document_root']);tgtroot=pathlib.Path(site['document_root'])
    if file_sha(srcroot/ASSETS[3])!=file_sha(SOURCE_CANON):
        raise RuntimeError('SOURCE_MAINTENANCE_IS_NOT_CANONICAL')
    mismatch=[];sha={}
    for rel in ASSETS:
        actual=file_sha(tgtroot/rel);want=file_sha(srcroot/rel)
        if not want:raise RuntimeError('ORIGINAL_SOURCE_VISUAL_ASSET_MISSING:'+rel)
        sha[rel]={'source_sha256':want,'target_sha256':actual}
        if actual!=want:mismatch.append('SYNC_SOURCE_ASSET:'+rel)
    expected=expected_config(tgt) if tgt.get('readiness') else None
    config=inspect_json(tgtroot/SNAPSHOT)
    config_stale=(expected is None or config!=expected)
    if config_stale:mismatch.append('REBUILD_TENANT_LIVE_KIT_MAINTENANCE_SNAPSHOT')
    if not tgt['bootstrap_includes_provider']:mismatch.append('COMPANY_PLUGIN_BOOTSTRAP_PROVIDER_NOT_INCLUDED')
    if not tgt['readiness']:mismatch.append('COMPANY_APPEARANCE_RUNTIME_NOT_ACTIVE')
    elif tgt['readiness']['state']!='SOURCE_SYSTEM_APPEARANCE_READBACK_PASS':
        mismatch.extend('READBACK:'+x for x in tgt['readiness']['problems'])
    # An exact 313 submission request is preparation, NEVER execution authority.
    # Match the current diff and each before/after digest so a stale request
    # cannot be presented as ready after either site changes.
    operation_request=None
    requested=[x for x in (BASE/'artifacts').glob(args.company+'-admin-experience-original-313-*/operation-request.json')]
    for request_file in sorted(requested,reverse=True)[:10]:
        candidate=inspect_json(request_file)
        if not isinstance(candidate,dict):continue
        if (candidate.get('contract')!='mad4b.company-admin-appearance-exact-original-313-request.v1'
            or candidate.get('company_code')!=args.company
            or candidate.get('environment')!=args.environment
            or candidate.get('canonical_origin')!=site['origin']
            or candidate.get('site_uuid')!=site['site_uuid']
            or candidate.get('native_proof_issued') is not False):continue
        files=candidate.get('files')
        if not isinstance(files,list) or len(files)!=len([a for a in ASSETS if sha[a]['source_sha256']!=sha[a]['target_sha256']]):continue
        verified=True
        seen=set()
        for f in files:
            if not isinstance(f,dict):verified=False;break
            name=f.get('relative_path')
            if name in seen or name not in sha or sha[name]['source_sha256']==sha[name]['target_sha256']:
                verified=False;break
            seen.add(name)
            if f.get('desired_sha256')!=sha[name]['source_sha256'] or f.get('before_sha256')!=sha[name]['target_sha256']:
                verified=False;break
        if verified:
            operation_request={'path':str(request_file),'request_sha256':candidate.get('request_sha256'),
                'file_count':len(files),'state':'PREPARED_NOT_SUBMITTED_OR_AUTHORIZED'}
            break
    authorized=all((plan.get('state')=='READY_FOR_EXACT_NATIVE_WRITE',
        plan.get('selected_channel')=='native_company_wordpress_write',
        plan.get('execution_allowed') is True,
        plan.get('remote_write_permitted') is True,
        plan.get('readback_required') is True,
        plan.get('site_uuid')==site['site_uuid'],
        plan.get('canonical_origin')==site['origin']))
    state='APPEARANCE_SOURCE_PARITY_VERIFIED' if not mismatch else 'APPEARANCE_SOURCE_RECONCILE_REQUIRED'
    record={'contract':'mad4b.company-system-appearance-broker-reconcile.v1',
      'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
      'company':args.company,'environment':args.environment,
      'source':source['origin'],'source_uuid':src['site_uuid'],
      'runtime_container_detected_from_live_identity':site['container'],
      'target':tgt['origin'],'target_uuid':tgt['site_uuid'],
      'state':state,'live_kit_id':(tgt.get('readiness') or {}).get('active_elementor_kit'),
      'language_switcher':(tgt.get('readiness') or {}).get('login_switcher_provider'),
      'source_asset_hashes':sha,'target_snapshot_fresh':not config_stale,
      'pending_actions':sorted(set(mismatch)),
      'native_write_authorized':authorized,'central_execution_state':plan.get('state'),
      'original_313_submission_preflight_state':plan.get('original_305_313_submission_preflight_state'),
      'original_313_submission_preflight_passed':plan.get('original_305_313_submission_preflight_passed') is True,
      'original_313_submission_is_execution_authority':False,
      'independent_postapply_313_receipt_required':True,
      'exact_operation_request':operation_request,
      'native_original_313_job_submitted_by_this_plan':False,
      'browser_acceptance_required':True,
      'login_email_or_user_permissions_changed':False,
      'payment_orders_or_content_changed':False,'target_mutated':False}
    if args.mode=='verify':
        record['state']='SOURCE_APPEARANCE_READBACK_PASS' if not mismatch else 'SOURCE_APPEARANCE_READBACK_HOLD'
    atomic_json(report_file(args.company,args.environment),record)
    print(json.dumps(record,ensure_ascii=False))
    return 0 if args.mode=='plan' or record['state'] in (
       'SOURCE_APPEARANCE_READBACK_PASS') else 2
if __name__=='__main__':
    try:sys.exit(main())
    except (RuntimeError,ValueError,OSError,KeyError,subprocess.SubprocessError) as e:
        print(json.dumps({'state':'HOLD','reason':str(e)[:300],
             'production_mutated':False,'users_or_email_changed':False}));sys.exit(3)
