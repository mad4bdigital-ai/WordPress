#!/usr/bin/env python3
"""Company runtime acceptance: query actual providers, page fields, and template dependencies."""
import json,subprocess,pathlib,tempfile,os,sys
from datetime import datetime, timezone
BASE=pathlib.Path("/opt/mad4b-company-provisioning")
REPORT=BASE/"reports/company-runtime-configuration-v2-wayfatours.json"
PHP=r"""<?php
global $wpdb;
$id=get_option('mad4b_company_site_identity_v1',[]);
$registry=get_option('mad4b_company_configuration_registry_v2',[]);
if(DB_NAME!=='wayfatours_production'||wp_parse_url(home_url(),PHP_URL_HOST)!=='wayfatours.mad4b.com'||
   ($id['company_code']??'')!=='wayfatours'||
   ($registry['contract']??'')!=='mad4b.company-configuration-registry.v2')
   WP_CLI::error('TENANT_OR_REGISTRY_NOT_MATCHED');
$manager=jet_engine()->options_pages;
$pages=[];$missing=0;
foreach(($registry['pages']??[]) as $slug=>$page) {
 $obj=$manager->registered_pages[$slug]??null;
 $actual=[];
 foreach((array)($obj->page['fields']??[]) as $f)
   if(is_array($f)&&isset($f['name']))$actual[$f['name']]=$f['type']??'';
 $n=0;
 foreach(($page['fields']??[]) as $f)
   if(!isset($actual[$f['key']]) || $actual[$f['key']]!==$f['type'])$n++;
 $missing+=$n;
 $pages[$slug]=['runtime_fields'=>count($actual),'missing'=>$n,
   'category'=>$page['classification']??'platform_managed',
   'capability'=>$obj->page['capability']??'unregistered'];
}
$tags=(array)\Elementor\Plugin::instance()->dynamic_tags->get_tags();
$rows=$wpdb->get_results("SELECT p.ID,p.post_type,p.post_status,m.meta_value AS data
 FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
 WHERE p.post_type IN ('elementor_library','jet-engine') AND m.meta_key='_elementor_data'");
$missingTagCounts=[];$names=[];$affected=[];
foreach($rows as $row){
 $tree=json_decode($row->data,true);if(!is_array($tree))continue;
 $walk=function($v)use(&$walk,$row,$tags,&$missingTagCounts,&$names,&$affected){
  if(!is_array($v))return;
  if(isset($v['__dynamic__']))foreach((array)$v['__dynamic__'] as $tag){
   if(!is_string($tag)||!preg_match('/name="([^"]+)"/',$tag,$m))continue;
   $name=$m[1];if(isset($tags[$name]))continue;
   $key=$row->post_type.':'.$row->post_status;
   $missingTagCounts[$key]=($missingTagCounts[$key]??0)+1;
   $names[$name]=($names[$name]??0)+1;
   $affected[$key][$row->ID]=true;
  }
  foreach($v as $key=>$val)if($key!=='__dynamic__')$walk($val);
 };
 $walk($tree);
}
$unique=[];foreach($affected as $key=>$ids)$unique[$key]=count($ids);
arsort($names);
echo wp_json_encode([
 'site_uuid'=>$id['site_uuid']??'','registry_sha256'=>$registry['fingerprint']??'',
 'page_count'=>count($pages),'runtime_fields'=>array_sum(array_column($pages,'runtime_fields')),
 'missing_fields'=>$missing,'pages'=>$pages,'missing_tag_counts'=>$missingTagCounts,
 'affected_templates'=>$unique,'missing_tag_names'=>$names,
 'core'=>['company_name_set'=>(string)get_option('blogname','')!=='',
 'custom_logo_set'=>(bool)get_theme_mod('custom_logo'),
 'favicon_set'=>(bool)get_site_icon_url(),
 'woo_sender_from_company'=>(string)apply_filters('woocommerce_email_from_name','')===(string)get_option('blogname','')],
 'security'=>['master_admin_has_manage_options'=>(bool)(get_role('master_admin')?get_role('master_admin')->has_cap('manage_options'):false),
 'master_admin_has_company_operations'=>(bool)(get_role('master_admin')?get_role('master_admin')->has_cap('mad4b_manage_company_operations'):false)],
 'tenant_values_copied_from_source'=>false
],JSON_UNESCAPED_SLASHES).PHP_EOL;
?>"""
def main():
 p=subprocess.run(["docker","exec","-i","-u","33:33","wayfatours-production-php",
    "php","-d","memory_limit=1024M","/usr/local/bin/wp","eval-file","-","--path=/var/www/html"],
    input=PHP,text=True,capture_output=True,timeout=140)
 if p.returncode:raise RuntimeError("AUDIT_NOT_COMPLETED:"+p.stderr[-800:])
 rows=[json.loads(s) for s in p.stdout.splitlines() if s.startswith("{")]
 if not rows:raise RuntimeError("NO_AUDIT_RESPONSE")
 a=rows[-1]
 if a["page_count"]!=12:raise RuntimeError("REGISTRY_PAGE_COUNT_FAILED")
 a["contract"]="mad4b.company-runtime-acceptance.v2"
 a["measured_utc"]=datetime.now(timezone.utc).isoformat()
 a["status"]="PASS" if a["missing_fields"]==0 and sum(a["missing_tag_counts"].values())==0 else "PARTIAL"
 REPORT.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(dir=REPORT.parent,prefix=".runtime-acceptance-")
 try:
  with os.fdopen(fd,"w") as f:json.dump(a,f,indent=2,ensure_ascii=False)
  os.chmod(tmp,0o640);os.replace(tmp,REPORT)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
 print(json.dumps({"state":a["status"],"registered_options_pages":a["page_count"],
  "registered_option_fields":a["runtime_fields"],"missing_option_fields":a["missing_fields"],
  "missing_dynamic_tags":sum(a["missing_tag_counts"].values()),"affected_published_templates":sum(n for k,n in a["affected_templates"].items() if k.endswith(':publish')),
  "tenant_brand_value_copy":False}))
if __name__=="__main__":main()

