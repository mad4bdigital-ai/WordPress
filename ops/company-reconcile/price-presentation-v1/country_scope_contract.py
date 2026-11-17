#!/usr/bin/env python3
"""Identity-bound and read-only geographic scope reconciliation.

Country activation is not sale authority. Explicit Service Activation company
territory and canonical country requirements are verified independently for
source and tenant before explaining intentional draft and Rate holds.
"""
from __future__ import annotations
import json
import re
import subprocess

CONTRACT='mad4b.company-geographic-operating-scope-runtime-readback.v1'
CONTAINER=re.compile(r'^php-[a-z0-9-]{10,80}$')
SHA=re.compile(r'^[a-f0-9]{64}$')
WP=r'''global $wpdb;
$profile=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
$class='MAD4B_Company_Country_Operating_Scope_V1';
if(!class_exists($class))throw new RuntimeException('COMPANY_SCOPE_POLICY_NOT_LOADED');
$service=get_option('service-activation',[]);
$dest=get_option('destination-activation',[]);
if(!is_array($service)||!is_array($dest))throw new RuntimeException('SOURCE_OPTION_INVALID');
$ids=get_posts(['post_type'=>'tours-and-activities','post_status'=>['publish','draft'],'numberposts'=>160,'fields'=>'ids','meta_query'=>[['key'=>$class::META,'compare'=>'EXISTS']]]);
$combined=get_posts(['post_type'=>'tours-and-activities','post_status'=>['publish','draft'],'numberposts'=>160,'fields'=>'ids','tax_query'=>[['taxonomy'=>'tour-types','field'=>'slug','terms'=>['combined-tours']]]]);
$ids=array_unique(array_merge($ids,$combined));sort($ids,SORT_NUMERIC);
$rows=[];
foreach($ids as $id){$decision=$class::decision((int)$id);
 $rows[]=['id'=>(int)$id,'language'=>apply_filters('wpml_post_language_details',null,$id)['language_code']??null,
 'title_hash'=>hash('sha256',get_the_title($id)),'status'=>get_post_status($id),
 'combined'=>$decision['is_combined'],'required_country_ids'=>$decision['country_ids'],
 'scope_code'=>$decision['code'],'excluded_country_ids'=>$decision['excluded_country_ids']];}
// A tour may be sale-eligible in EN but incorrectly blocked in ES/DE/FR/AR/IT
// when a translated term parent is mistaken for a different country.
// The original single-language reconciliation missed this production defect.
// Audit every active locale, not a fixed central list, and fail closed.
$cfg=get_option('icl_sitepress_settings',[]);
$active=is_array($cfg)?($cfg['active_languages']??[]):[];
if(!is_array($active) || !$active)throw new RuntimeException('WPML_ACTIVE_LANGUAGES_UNAVAILABLE');
$active=array_values(array_unique(array_filter($active,fn($v)=>is_string($v)&&preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$v))));
if(!$active)throw new RuntimeException('WPML_ACTIVE_LANGUAGE_CODES_INVALID');
$language_scope_coverage=[];
foreach($active as $lang){
 do_action('wpml_switch_language',$lang);
 if((string)apply_filters('wpml_current_language',null)!==$lang)
     throw new RuntimeException('WPML_SCOPE_LANGUAGE_CONTEXT_MISMATCH:'.$lang);
 $published=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
    'numberposts'=>2501,'fields'=>'ids','suppress_filters'=>false]);
 if(count($published)>2500)throw new RuntimeException('GEO_SCOPE_PUBLISHED_LANGUAGE_AUDIT_TOO_LARGE:'.$lang);
 $invalid=[];$codes=[];
 foreach($published as $post_id){
   $decision=$class::decision((int)$post_id);
   $code=$decision['code'];
   $codes[$code]=($codes[$code]??0)+1;
   if($code!=='IN_SCOPE')$invalid[]=(int)$post_id;
 }
 $countries=function_exists('native_destination_approved')?native_destination_approved($lang):[];
 $language_scope_coverage[]=['language'=>$lang,'published_tour_count'=>count($published),
    'geographic_states'=>$codes,'invalid_published_tour_ids'=>$invalid,
    'menu_country_ids'=>array_values(array_map(fn($v)=>(int)$v['id'],$countries))];
}
$rate_items=$wpdb->get_results("SELECT DISTINCT r.ID AS rate_id,p.ID AS tour_id FROM {$wpdb->posts} r INNER JOIN {$wpdb->postmeta} m ON m.post_id=r.ID AND m.meta_key='related_tour_id' INNER JOIN {$wpdb->posts} p ON p.ID=CAST(m.meta_value AS UNSIGNED) WHERE r.post_type='tour-rates' AND r.post_status='publish' AND p.post_type='tours-and-activities' AND p.post_status='draft' ORDER BY r.ID LIMIT 1001",ARRAY_A);
if(count($rate_items)>1000)throw new RuntimeException('RATE_DRAFT_PARENT_AUDIT_TOO_LARGE');
$rate_parent_counts=[];$draft_rate_total=0;$held_draft_rate_count=0;
foreach($rate_items as $row){$draft_rate_total++;$tour=(int)$row['tour_id'];
 if(!isset($rate_parent_counts[$tour]))$rate_parent_counts[$tour]=['count'=>0,'scope_code'=>$class::decision($tour)['code']];
 $rate_parent_counts[$tour]['count']++;
 if($rate_parent_counts[$tour]['scope_code']==='HOLD_COUNTRY_NOT_ENABLED')$held_draft_rate_count++;
}
ksort($rate_parent_counts,SORT_NUMERIC);
$path=WPMU_PLUGIN_DIR.'/020-mad4b-company-country-operating-scope-v1.php';
echo wp_json_encode(['contract'=>'mad4b.country-scope.wp-readback.v1',
 'origin'=>untrailingslashit(home_url('/')),'site_uuid'=>$profile['site_uuid']??null,
 'environment'=>$profile['environment']??null,
 'profile_ready'=>!empty($profile['configured'])&&!empty($profile['origin_match'])&&!empty($profile['environment_match'])&&!empty($profile['deployment_binding_match']),
 'module_sha256'=>is_file($path)?hash_file('sha256',$path):null,
 'policy_version'=>$service[$class::VERSION]??null,
 'service_modes'=>$service['active_services']??[],
 'service_tour_discovery_allowed'=>function_exists('service_channel_allowed')?service_channel_allowed('tours','destination_discovery'):null,
 'allowed_country_ids'=>$class::operating_countries(),
 'destination_catalog_country_ids'=>$class::catalog_countries(),
 'pricing_workspace_class_available'=>class_exists('MAD4B_Pricing_Workspace_V1'),
 'active_language_codes'=>$active,'language_scope_coverage'=>$language_scope_coverage,
 'tour_rows'=>$rows,'draft_published_rate_total'=>$draft_rate_total,
 'draft_published_rate_country_hold_count'=>$held_draft_rate_count,
 'draft_published_rate_parent_counts'=>$rate_parent_counts,
 'read_only'=>true],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);'''


def probe(site:dict,timeout:int=85)->dict:
    c=str(site['container'])
    if not CONTAINER.fullmatch(c):raise ValueError('COUNTRY_SCOPE_ENROLLED_CONTAINER_REQUIRED')
    res=subprocess.run(['docker','exec','-u','33:33',c,'wp','--path=/var/www/html','--skip-themes','eval',WP],
                       capture_output=True,text=True,timeout=timeout,check=False)
    if res.returncode:
        raise RuntimeError('COUNTRY_SCOPE_NATIVE_READBACK_FAILED:'+res.stderr[-180:])
    lines=[s for s in res.stdout.splitlines() if s.startswith('{"contract":"mad4b.country-scope.wp-readback.v1"')]
    if len(lines)!=1:raise ValueError('COUNTRY_SCOPE_READBACK_CARDINALITY')
    data=json.loads(lines[0])
    if (data.get('origin')!=site['origin'] or data.get('read_only') is not True
        or data.get('profile_ready') is not True or not data.get('site_uuid')):
        raise ValueError('COUNTRY_SCOPE_PROFILE_OR_ORIGIN_DRIFT')
    if not SHA.fullmatch(str(data.get('module_sha256',''))):
        raise ValueError('COUNTRY_SCOPE_MODULE_UNVERIFIED')
    if data.get('policy_version')!='1' or not data.get('allowed_country_ids'):
        raise ValueError('COUNTRY_SCOPE_POLICY_NOT_CONFIGURED')
    if set(data['allowed_country_ids'])-set(data['destination_catalog_country_ids']):
        raise ValueError('OPERATING_COUNTRY_NOT_IN_DESTINATION_CATALOG')
    return data


def assess(source:dict,target:dict)->dict:
    reasons=[]
    same_module=bool(source.get('module_sha256') and source['module_sha256']==target.get('module_sha256'))
    if not same_module:reasons.append('GEOGRAPHIC_SCOPE_SOURCE_TARGET_MODULE_DRIFT')
    if source.get('policy_version')!='1' or target.get('policy_version')!='1':
        reasons.append('GEOGRAPHIC_SCOPE_POLICY_VERSION_MISSING')
    # Language-complete live publication contract. A PASS on the canonical
    # English source alone cannot certify company geography for every locale.
    for site_label,site in (('source',source),('target',target)):
        active=site.get('active_language_codes')
        coverage=site.get('language_scope_coverage')
        if (not isinstance(active,list) or not active or
            not isinstance(coverage,list) or
            [x.get('language') for x in coverage]!=active or
            len(set(active))!=len(active)):
            reasons.append('WPML_GEOGRAPHIC_LANGUAGE_COVERAGE_REQUIRED:'+site_label)
            continue
        for lang in coverage:
            invalid=lang.get('invalid_published_tour_ids')
            total=lang.get('published_tour_count')
            if not isinstance(invalid,list) or not isinstance(total,int) or total<0:
                reasons.append('INVALID_WPML_GEOGRAPHIC_EVIDENCE:'+site_label+':'+str(lang.get('language')))
            elif invalid:
                reasons.append('PUBLISHED_TOURS_OUTSIDE_OPERATING_SCOPE:'+site_label+':'+str(lang.get('language')))
    src={row['id']:row for row in source.get('tour_rows',[])}
    dst={row['id']:row for row in target.get('tour_rows',[])}
    held=[];unverified=[];editorial=[]
    for tid,row in dst.items():
        if row['scope_code']=='HOLD_COUNTRY_EVIDENCE_MISSING':
            unverified.append(tid)
        if tid not in src:continue
        r=src[tid]
        if row['required_country_ids']!=r['required_country_ids']:
            reasons.append('TOUR_COUNTRY_REQUIREMENTS_SOURCE_TARGET_MISMATCH:'+str(tid))
        if r['status']=='publish' and row['status']=='draft':
            if row['scope_code']=='HOLD_COUNTRY_NOT_ENABLED' and r['scope_code']=='IN_SCOPE':
                held.append(tid)
            else:editorial.append(tid)
    if unverified:reasons.append('COMBINED_TOUR_COUNTRY_EVIDENCE_MISSING')
    rate_total=int(target.get('draft_published_rate_total') or 0)
    rate_held=int(target.get('draft_published_rate_country_hold_count') or 0)
    if rate_held>rate_total:reasons.append('GEO_RATE_HOLD_COUNT_EXCEEDS_PARENT_RATES')
    return {'contract':CONTRACT,'state':'PASS_VERIFIED' if not reasons else 'HOLD_GEOGRAPHIC_SCOPE_CONTRACT',
            'reason_codes':reasons,'source':source,'target':target,
            'module_source_target_sha_match':same_module,
            'source_published_target_draft_scope_held_ids':held,
            'source_published_target_draft_editorial_ids':editorial,
            'scope_held_published_rate_draft_parent_count':rate_held,
            'total_published_rate_draft_parent_count':rate_total,
            'non_scope_held_published_rate_draft_parent_count':rate_total-rate_held,
            'read_only':True,'can_auto_publish':False,'can_auto_enable_countries':False,
            'can_auto_apply':False,'mutates_tenant':False}


def observe(source_site:dict,target_site:dict)->dict:
    return assess(probe(source_site),probe(target_site))
