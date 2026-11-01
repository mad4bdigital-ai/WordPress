#!/usr/bin/env python3
"""Mandatory tenant runtime acceptance for MAD4B company provisioning.

Every readiness evaluation probes tenant WordPress itself, not only copied DB
schema. Never copies credentials, business values, attachments or customer data.
Company policy is data, engine is reusable.
"""
import argparse,datetime,hashlib,json,os,pathlib,re,ssl,subprocess,tempfile,urllib.request
BASE=pathlib.Path("/opt/mad4b-company-provisioning")
POLICY=BASE/"policies/company-governed-runtime-contract-v1.json"
REPORT=BASE/"reports/company-governed-runtime-gates-wayfatours.json"
PHP=r"""<?php
global $wpdb;
$site=get_option('mad4b_company_site_identity_v1',[]);
$o=array('host'=>wp_parse_url(home_url(),PHP_URL_HOST),
 'company'=>$site['company_code']??'','site_uuid'=>$site['site_uuid']??'',
 'database'=>DB_NAME,'environment'=>wp_get_environment_type(),'plugins'=>[],
 'core'=>[],'providers'=>[],'template'=>[]);
foreach((array)get_option('active_plugins',[]) as $name){
 $slug=explode('/',$name,2)[0];$o['plugins'][$slug]=true;
}
$o['core']=[
 'elementor_kit'=>(int)get_option('elementor_active_kit'),
 'company_options_registry'=>(get_option('mad4b_company_configuration_registry_v2',[])['fingerprint']??null),
 'cpt_tours'=>post_type_exists('tours-and-activities'),
 'cpt_properties'=>post_type_exists('properties'),
 'cpt_transport'=>post_type_exists('transportations'),
 'wc_available'=>class_exists('WooCommerce'),
 'wpml_available'=>defined('ICL_SITEPRESS_VERSION')];
$desired_functions=array('pricing_audience_quote',
  'pricing_currency_convert','tour_rate_native_card_rate_id',
  'tour_component_price_evidence','transport_component_price_current_id');
foreach($desired_functions as $name)$o['providers']['functions'][$name]=function_exists($name);
$tags=class_exists('Elementor\\Plugin')?
  (array)\Elementor\Plugin::instance()->dynamic_tags->get_tags():[];
$o['providers']['tag_count']=count($tags);
$counts=[];$names=[];$affected=[];$references=[];
$posts=$wpdb->get_results("SELECT p.ID,p.post_type,p.post_status,
  m.meta_value AS data,t.meta_value AS template_type,
  c.meta_value AS conditions
 FROM {$wpdb->posts} p
 JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_elementor_data'
 LEFT JOIN {$wpdb->postmeta} t ON t.post_id=p.ID AND t.meta_key='_elementor_template_type'
 LEFT JOIN {$wpdb->postmeta} c ON c.post_id=p.ID AND c.meta_key='_elementor_conditions'
 WHERE p.post_type IN ('elementor_library','jet-engine')",ARRAY_A);
$mediaByState=[];$linkByState=[];$mediaSamples=[];$missingRefs=[];
$sourceHosts=['tours.dev.mad4b.com','staging.egypttourgates.com',
 'egypttourgates.com','allroyalegypt.com','wovacation.com','localhost'];
$theme=['published_headers'=>0,'published_singles'=>0,'published_archives'=>0,
 'published_with_conditions'=>0,'draft_with_conditions'=>0,'draft_theme_conditions'=>[]];
foreach($posts as $row){
 $status=$row['post_status'];$type=$row['template_type'];
 if($row['post_type']==='elementor_library'){
  if($status==='publish'){
   if($type==='header')$theme['published_headers']++;
   if(in_array($type,['single-page','single-post'],true))$theme['published_singles']++;
   if($type==='archive')$theme['published_archives']++;
  }
  $condition=maybe_unserialize($row['conditions']);
  if(is_array($condition)&&count($condition)){
   if($status==='publish')$theme['published_with_conditions']++;
   if($status==='draft') {
    $theme['draft_with_conditions']++;
    if(count($theme['draft_theme_conditions'])<12)
      $theme['draft_theme_conditions'][]=['id'=>(int)$row['ID'],
        'type'=>$type,'conditions'=>array_slice($condition,0,3)];
   }
  }
 }
 $data=json_decode($row['data'],true);if(!is_array($data))continue;
 $walk=function($node)use(&$walk,$row,$tags,&$counts,&$names,&$affected,
  &$mediaByState,&$linkByState,&$mediaSamples,$sourceHosts){
  if(!is_array($node))return;
  if(isset($node['__dynamic__'])&&is_array($node['__dynamic__'])){
   foreach($node['__dynamic__'] as $s){
    if(!is_string($s)||!preg_match('/name="([^"]+)"/',$s,$m))continue;
    if(isset($tags[$m[1]]))continue;
    $key=$row['post_type'].':'.$row['post_status'];
    $counts[$key]=($counts[$key]??0)+1;
    $names[$m[1]]=($names[$m[1]]??0)+1;
    $affected[$key][$row['ID']]=true;
   }
  }
  foreach($node as $field=>$v){
   if($field==='__dynamic__')continue;
   if(is_array($v)){$walk($v);continue;}
   if(!is_string($v)||strlen($v)>1000)continue;
   $host=strtolower((string)wp_parse_url($v,PHP_URL_HOST));
   if(!$host||!in_array($host,$sourceHosts,true))continue;
   $media=(strpos($v,'/wp-content/uploads/')!==false ||
      preg_match('~\\.(png|webp|jpg|jpeg|svg|gif)(?:[?#]|$)~i',$v));
   $state=$row['post_type'].':'.$row['post_status'];
   if($media)$mediaByState[$state]=($mediaByState[$state]??0)+1;
   else $linkByState[$state]=($linkByState[$state]??0)+1;
   if(count($mediaSamples)<14)$mediaSamples[]=['id'=>(int)$row['ID'],
    'state'=>$state,'hostname'=>$host,'kind'=>$media?'media':'url'];
  }
 };
 $walk($data);
}
$unique=[];foreach($affected as $type=>$ids)$unique[$type]=count($ids);
arsort($names);
$o['template']=[
 'scanned_design_records'=>count($posts),
 'missing_dynamic_tag_instances'=>$counts,
 'missing_dynamic_tag_unique_templates'=>$unique,
 'missing_provider_names'=>$names,
 'source_media_references'=>$mediaByState,
 'source_internal_links'=>$linkByState,
 'old_reference_samples'=>$mediaSamples,
 'theme_builder'=>$theme];
echo wp_json_encode($o,JSON_UNESCAPED_SLASHES).PHP_EOL;
?>"""
def wordpress(cfg):
 cmd=["docker","exec","-i","-u","33:33",cfg["wordpress_container"],"php",
  "-d","memory_limit=1024M","/usr/local/bin/wp","eval-file","-",
  "--path=/var/www/html"]
 p=subprocess.run(cmd,input=PHP,capture_output=True,text=True,timeout=150)
 if p.returncode:raise RuntimeError("TENANT_WP_RUNTIME_UNAVAILABLE:"+p.stderr[-700:])
 for ln in reversed(p.stdout.splitlines()):
  if ln.startswith("{"):return json.loads(ln)
 raise RuntimeError("TENANT_WP_RUNTIME_MISSING_OUTPUT")
def login_check(cfg):
 url="https://"+cfg["site_host"]+"/wp-login.php"
 try:
  with urllib.request.urlopen(url,context=ssl.create_default_context(),timeout=18) as r:
   body=r.read(350000).decode("utf-8","replace")
   return {"status":r.status,"tls_verified":True,
    "presentation_loaded":"mad4b-company-login-presentation" in body,
    "source_brand_leaked":bool(re.search('allroyalegypt\\.com|tours\\.dev\\.mad4b\\.com|wovacation\\.com',body,re.I)),
    "layout_card":"border-radius:18px" in body,
    "company_label":cfg["company_name"].lower() in body.lower()}
 except Exception as e:
  return {"status":None,"tls_verified":False,"reason":str(type(e).__name__)}
def mkstate(ok,detail,next_action):
 return {"state":"PASS" if ok else "BLOCKED","detail":detail,"next_action":next_action}
def evaluate(cfg,site,login):
 if (site["company"],site["host"],site["site_uuid"],site["database"]) != (
       cfg["company_code"],cfg["site_host"],cfg["site_uuid"],cfg["database"]):
  raise RuntimeError("TENANT_IDENTITY_MISMATCH")
 plugs=site["plugins"];core=site["core"];t=site["template"];provided=site["providers"]
 missing_plugins=[p for p in cfg["required_runtime_plugins"] if not plugs.get(p)]
 missing_commerce=[p for p in cfg["required_commercial_plugins"] if not plugs.get(p)]
 critical_funcs=[k for k,v in provided["functions"].items() if not v]
 missing_published=sum(v for k,v in t["missing_dynamic_tag_instances"].items()
   if k.endswith(":publish"))
 affected_published=sum(v for k,v in t["missing_dynamic_tag_unique_templates"].items()
   if k.endswith(":publish"))
 published_source=sum(v for k,v in t["source_media_references"].items()
   if k.endswith(":publish"))+sum(v for k,v in t["source_internal_links"].items()
   if k.endswith(":publish"))
 pending_sources=sum(v for k,v in t["source_media_references"].items()
   if k.endswith(":draft"))+sum(v for k,v in t["source_internal_links"].items()
   if k.endswith(":draft"))
 theme=t["theme_builder"]
 stages={}
 stages["runtime_code_bootstrap"]=mkstate(not missing_plugins and all(
   core.get(n) for n in ("cpt_tours","cpt_properties","cpt_transport","wc_available","wpml_available")),
   f"required_plugins_missing={missing_plugins}; core_registered={core}",
   "PROVISION_ONLY_COMPATIBLE_PORTABLE_PLUGINS_AND_MU_PROVIDERS")
 stages["commercial_pricing_and_rate_engines"]=mkstate(
   not missing_commerce and not critical_funcs and missing_published==0,
   f"required_plugins_missing={missing_commerce}; missing_runtime_functions={critical_funcs}; "
   f"published_missing_dynamic_tags={missing_published}",
   "INSTALL_PORTABLE_PRICING_RATE_ENGINES_AND_VERIFY_LIVE_QUOTE_RULES")
 stages["commercial_transport_rate_engine"]=mkstate(
   plugs.get("woocommerce-travel-bridge",False) and
   provided["functions"].get("transport_component_price_current_id",False),
   "transport source-native bridge and current rate resolver must both be live",
   "RESTORE_TENANT_TRANSPORT_RATE_AND_PRICING_PROVIDER_WITH_READBACK")
 stages["published_template_runtime_dependencies"]=mkstate(missing_published==0,
   f"published_unregistered_tag_occurrences={missing_published}; "
   f"affected_published_templates={affected_published}",
   "RECOVER_REAL_TAG_PROVIDERS_NO_FAKE_BLANK_PRICES")
 stages["company_media_asset_mappings"]=mkstate(published_source==0,
   f"published_source_media_or_link_refs={published_source}; draft_refs_pending={pending_sources}",
   "RESOLVE_OR_DEFER_DRAFT_SOURCE_MEDIA_TO_TENANT_OWNED_JETENGINE_PAGE_FIELDS")
 stages["company_page_url_and_wpml_links"]=mkstate(
   published_source==0 and pending_sources==0,
   f"published_legacy_references={published_source}; pending_draft_references={pending_sources}",
   "MAP_TENANT_PAGE_IDS_WPML_LANGUAGE_URLS_AND_ENROLL_DEFERRED_FIELDS")
 stages["elementor_theme_builder_conditions"]=mkstate(
   theme["published_headers"]>0 and theme["published_singles"]>0 and
   theme["published_with_conditions"]>=2,
   f"published_headers={theme['published_headers']}; published_singles={theme['published_singles']}; "
   f"active_conditional_templates={theme['published_with_conditions']}; "
   f"draft_conditions={theme['draft_with_conditions']}",
   "RESOLVE_COMPANY_HEADER_ARCHIVE_SINGLE_CONDITIONS_AND_PREVENT_OVERLAPS")
 stages["company_login_code_and_identity"]=mkstate(
   login.get("status")==200 and login.get("tls_verified") and
   login.get("presentation_loaded") and login.get("layout_card") and
   login.get("company_label") and not login.get("source_brand_leaked"),
   f"login={login}",
   "RESTORE_LOGIN_PRESENTATION_WITH_TENANT_ONLY_LOGO_AND_COMPANY_COLORS")
 stages["company_options_runtime_registry"]=mkstate(
   bool(core.get("company_options_registry")),
   f"company_registry_present={bool(core.get('company_options_registry'))}",
   "SYNC_RUNTIME_OPTIONS_DEFINITIONS_NOT_COMPANY_VALUES")
 return stages
def persist(file,rec):
 file.parent.mkdir(parents=True,exist_ok=True)
 fd,temp=tempfile.mkstemp(dir=file.parent,prefix=".runtime-gates-")
 try:
  with os.fdopen(fd,"w") as f:json.dump(rec,f,indent=2,ensure_ascii=False);f.write("\n")
  os.chmod(temp,0o640);os.replace(temp,file)
 finally:
  if os.path.exists(temp):os.unlink(temp)
def run():
 cfg=json.loads(POLICY.read_text())
 if cfg.get("contract")!="mad4b.company-governed-runtime-contract.v1":
  raise RuntimeError("UNAPPROVED_RUNTIME_GATING_CONTRACT")
 site=wordpress(cfg);login=login_check(cfg);stages=evaluate(cfg,site,login)
 time=datetime.datetime.now(datetime.timezone.utc).isoformat()
 out={"contract":"mad4b.company-governed-runtime-gates.v1",
  "company":cfg["company_code"],"site_uuid":cfg["site_uuid"],
  "generated_utc":time,"policy_sha256":hashlib.sha256(POLICY.read_bytes()).hexdigest(),
  "stages":stages,"can_finalize_company_provisioning":all(
    v["state"]=="PASS" for v in stages.values()),
  "commercial_publication_authority":False,
  "tenant_mutated":False,
  "template_scan":site["template"],
  "login_probe":login,
  "missing_commercial_plugins":[p for p in cfg["required_commercial_plugins"] if not site["plugins"].get(p)]}
 persist(REPORT,out)
 print(json.dumps({"company":cfg["company_code"],"finalize":out["can_finalize_company_provisioning"],
  "stage_states":{k:v["state"] for k,v in stages.items()},
  "published_missing_tags":sum(v for k,v in site["template"]["missing_dynamic_tag_instances"].items()
     if k.endswith(":publish")),
  "source_draft_references":sum(site["template"]["source_media_references"].values())
      +sum(site["template"]["source_internal_links"].values()),
  "login_fixed":stages["company_login_code_and_identity"]["state"]=="PASS"}))
if __name__=="__main__":run()
