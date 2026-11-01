#!/usr/bin/env python3
"""MAD4B central runtime company configuration registry — metadata only, no business values."""
import json,hashlib,subprocess,pathlib,os,tempfile,base64,sys
BASE=pathlib.Path("/opt/mad4b-company-provisioning")
TARGETS={"central":("etg-commerce-staging-php","tours.dev.mad4b.com","etg_commerce_staging"),
         "wayfatours":("wayfatours-production-php","wayfatours.mad4b.com","wayfatours_production")}
SCRIPT=r"""<?php
if (!function_exists('jet_engine')||!class_exists('Elementor\\Plugin')) WP_CLI::error('PROVIDERS_NOT_LOADED');
$manager=jet_engine()->options_pages??null;if(!is_object($manager))WP_CLI::error('OPTIONS_MANAGER_MISSING');
$out=['host'=>wp_parse_url(home_url(),PHP_URL_HOST),'db'=>DB_NAME,'pages'=>[],'tags'=>[]];
foreach((array)$manager->registered_pages as $slug=>$obj) {
 $def=(array)($obj->page??[]);$fields=[];
 foreach((array)($def['fields']??[]) as $f){
   if(!is_array($f)||empty($f['name']))continue;
   $fields[]=['key'=>(string)$f['name'],'type'=>(string)($f['type']??''),
     'label'=>wp_strip_all_tags((string)($f['title']??$f['name']))];
 }
 $out['pages'][(string)$slug]=[
    'label'=>wp_strip_all_tags((string)($def['labels']['name']??$slug)),
    'capability'=>(string)($def['capability']??'manage_options'),
    'storage_type'=>(string)($obj->storage_type??'default'),
    'fields'=>$fields];
}
$tags=(array)\Elementor\Plugin::instance()->dynamic_tags->get_tags();
foreach($tags as $name=>$info)$out['tags'][]=(string)$name;
sort($out['tags']);ksort($out['pages']);
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
?>"""
def invoke(container,code,extra_env=()):
 cmd=["docker","exec","-i","-u","33:33",*extra_env,container,"php","-d","memory_limit=1024M",
      "/usr/local/bin/wp","eval-file","-","--path=/var/www/html"]
 p=subprocess.run(cmd,input=code,text=True,capture_output=True,timeout=105)
 if p.returncode:raise RuntimeError(f"WP_CALL_FAILED {container} {p.stderr[-900:]}")
 for line in reversed(p.stdout.splitlines()):
  if line.startswith("{"):return json.loads(line)
 raise RuntimeError("NO_READBACK "+container)
def classification(slug):
 if slug in ("look-and-feel","contact-and-help-info","global-offers","company-communication-operations"):
  return "company_editable"
 if slug=="mad4b-company-policy-library":return "approval_required"
 if slug in ("company-operations-policies","etg-dynamic-pricing-controls","calculate-booking-price"):
  return "approval_required"
 return "platform_managed"
def atomic(p,value):
 p.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(dir=p.parent,prefix=".runtime-registry-")
 try:
  with os.fdopen(fd,"w") as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write("\n")
  os.chmod(tmp,0o640);os.replace(tmp,p)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
def main():
 snapshots={}
 for key,(container,host,db) in TARGETS.items():
  value=invoke(container,SCRIPT)
  if value["host"]!=host or value["db"]!=db:raise RuntimeError("SITE_IDENTITY_FAILURE_"+key)
  snapshots[key]=value
 src=snapshots["central"];tenant=snapshots["wayfatours"]
 pages={}
 for slug,config in src["pages"].items():
  target=tenant["pages"].get(slug,{})
  local_fields={f["key"]:f for f in target.get("fields",[])}
  fields=[]
  for f in config["fields"]:
   actual=local_fields.get(f["key"])
   fields.append({**f,"tenant_provider_state":"READY" if actual and actual["type"]==f["type"] else "MISSING_OR_DIFFERENT"})
  extra=[f for f in target.get("fields",[]) if f["key"] not in {x["key"] for x in fields}]
  for f in extra:fields.append({**f,"tenant_provider_state":"TENANT_EXTENSION"})
  kind=classification(slug)
  pages[slug]={
   "classification":kind,
   "editable_by_company":kind=="company_editable",
   "owner_input_pending_allowed":kind=="company_editable" or kind=="approval_required",
   "source_runtime_count":len(config["fields"]),
   "tenant_runtime_count":len(target.get("fields",[])),
   "target_capability":target.get("capability"),
   "tenant_page_registered":bool(target),
   "values_storage":{"system":"tenant_jetengine_options_page","option_page":slug},
   "fields":fields}
 for slug,config in tenant["pages"].items():
  if slug not in pages:
   pages[slug]={"classification":"tenant_extension","editable_by_company":False,
    "owner_input_pending_allowed":False,"tenant_page_registered":True,
    "source_runtime_count":0,"tenant_runtime_count":len(config["fields"]),
    "target_capability":config["capability"],
    "values_storage":{"system":"tenant_jetengine_options_page","option_page":slug},
    "fields":[{**f,"tenant_provider_state":"TENANT_EXTENSION"} for f in config["fields"]]}
 registry={
   "contract":"mad4b.company-configuration-registry.v2",
   "definition_authority":"central_control_plane_runtime",
   "source_host":"tours.dev.mad4b.com",
   "value_authority":"tenant_site_jetengine_page_options",
   "tenant":"wayfatours",
   "pages":pages,
   "field_count":sum(len(v["fields"]) for v in pages.values()),
   "source_runtime_field_count":sum(len(v["fields"]) for v in src["pages"].values()),
   "tenant_runtime_field_count":sum(len(v["fields"]) for v in tenant["pages"].values()),
   "tag_providers":{"source":src["tags"],"tenant":tenant["tags"],
    "missing_on_tenant":sorted(set(src["tags"])-set(tenant["tags"]))},
   "rules":{"copy_source_values":False,"overwrite_tenant_values":False,
            "blank_company_fields_can_be_filled_after_workflow":True,
            "pricing_not_automatically_enabled":True,
            "runtime_provider_parity_is_independent_of_raw_schema":True,
            "core_derived_values_not_duplicated":True}
 }
 canon=json.dumps(registry,sort_keys=True,ensure_ascii=False,separators=(",",":"))
 registry["fingerprint"]=hashlib.sha256(canon.encode()).hexdigest()
 path=BASE/"policies/company-configuration-registry-v2.json"
 atomic(path,registry)
 print(json.dumps({"status":"REGISTRY_V2_GENERATED","pages":len(pages),
  "source_fields":registry["source_runtime_field_count"],
  "target_fields":registry["tenant_runtime_field_count"],
  "missing_runtime_provider_fields":sum(f["tenant_provider_state"]=="MISSING_OR_DIFFERENT"
        for p in pages.values() for f in p["fields"]),
  "missing_runtime_tags":len(registry["tag_providers"]["missing_on_tenant"]),
  "sha256":registry["fingerprint"]}))
 if sys.argv[1:]==["--write-metadata"]:
  writer=r"""<?php
$encoded=(string)getenv('MAD4B_REGISTRY_B64');
$payload=json_decode(base64_decode($encoded,true),true);
if(!is_array($payload)||($payload['contract']??'')!=='mad4b.company-configuration-registry.v2'
  ||count($payload['pages']??[])!==12||empty($payload['fingerprint']))
  WP_CLI::error('REGISTRY_SCHEMA_INVALID');
$host=wp_parse_url(home_url(),PHP_URL_HOST);
if(!in_array($host,['tours.dev.mad4b.com','wayfatours.mad4b.com'],true))WP_CLI::error('HOST_UNTRUSTED');
if(($host==='tours.dev.mad4b.com'&&DB_NAME!=='etg_commerce_staging')||
   ($host==='wayfatours.mad4b.com'&&DB_NAME!=='wayfatours_production'))
  WP_CLI::error('DATABASE_IDENTITY_INVALID');
$option='mad4b_company_configuration_registry_v2';
$previous=get_option($option,array());
if(($previous['fingerprint']??'')!==$payload['fingerprint'])
  update_option($option,$payload,false);
$read=get_option($option,array());
if(($read['fingerprint']??'')!==$payload['fingerprint'])WP_CLI::error('WRITTEN_REGISTRY_NOT_MATCHED');
echo wp_json_encode(['status'=>'REGISTRY_V2_VERIFIED','host'=>$host,
 'fingerprint'=>$read['fingerprint'],'pages'=>count($read['pages']),
 'source_values_copied'=>0]).PHP_EOL;
?>"""
  env=["-e","MAD4B_REGISTRY_B64="+base64.b64encode(json.dumps(registry,ensure_ascii=False,separators=(",",":")).encode()).decode()]
  for key,(container,_,__) in TARGETS.items():
   result=invoke(container,writer,env)
   print(json.dumps(result))
if __name__=="__main__":main()
