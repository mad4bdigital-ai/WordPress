<?php
// Read-only WP-CLI policy tests. Do not change live theme, options, or cache.
$checked=[];
$check=static function ($name,$truth) use (&$checked) {
    $checked[$name]=(bool)$truth;
    if(!$truth) throw new RuntimeException('test_failed_'.$name);
};
$collection=get_option('mad4b_perf_css_browser_evidence_v2',[]);
$record=$collection['records'][0]??[];
$plan=MAD4B_Company_Performance_Intelligence::adaptive_css_decisions_v2();
$actual=$plan['decisions'][0]['evaluation']??[];
$check('live_hold_lcp_regression',($actual['state']??'')==='hold_lcp_regression' && ($actual['decision']??'')==='native');
$check('real_two_pairs_and_p75',($actual['pairs']??0)===2 && ($actual['lcp_post_ttfb_delta_p75_ms']??0)===412 && ($actual['fcp_post_ttfb_delta_p75_ms']??0)===-2528);
$check('real_coverage_gap',count($plan['languages_missing_css_plan']??[])===5);
$ctx=['site_host'=>'tours.dev.mad4b.com','environment'=>'staging','path'=>'/','locale'=>'en','device'=>'mobile',
  'source_bundle_sha'=>$record['source_bundle_sha'],'elementor_doc_fingerprint'=>$record['elementor_doc_fingerprint'],
  'css_epoch'=>$record['css_epoch'],'approved_variant_shas'=>[$record['source_bundle_sha']],
  'discovery_fresh'=>true,'live_runtime_mode'=>'on'];
$good=$record;$good['pairs']=[];
for($i=0;$i<6;$i++) {
    $good['pairs'][]=['native'=>['ttfb_ms'=>1000,'fcp_ms'=>2000,'lcp_ms'=>3000,'cls'=>0.01,'js_errors'=>0],
        'candidate'=>['ttfb_ms'=>1200,'fcp_ms'=>1900,'lcp_ms'=>3000,'cls'=>0.01,'js_errors'=>0],
        'viewport_width'=>($i%2)===0?320:390,'cache_tier'=>$i<3?'cold':'warm',
        'order'=>($i%2)===0?'AB':'BA','same_document'=>true,'same_css_source'=>true,
        'interaction_state'=>'pass','visual_state'=>'pass'];
}
$good['captured_at_utc']=gmdate('c');
$eval=static fn($r,$c)=>MAD4B_Company_Performance_Intelligence::css_evidence_policy_v2($r,$c);
$x=$eval($good,$ctx);
$check('six_good_samples_eligible',($x['state']??'')==='passed_evidence' && $x['public_eligible']===true && $x['cold_pairs']===3 && $x['warm_pairs']===3);
$bad=$good;$bad['site_host']='wrong.example.com';$x=$eval($bad,$ctx);
$check('cross_tenant_rejected',$x['public_eligible']===false && in_array('source_scope_mismatch_site_host',$x['reason_codes'],true));
$bad=$good;$bad['source_bundle_sha']=str_repeat('1',64);$x=$eval($bad,$ctx);
$check('css_source_drift_rejected',$x['public_eligible']===false);
$bad=$good;$bad['captured_at_utc']=gmdate('c',time()-100*3600);$x=$eval($bad,$ctx);
$check('stale_evidence_rejected',$x['public_eligible']===false && in_array('evidence_stale_or_unclocked',$x['reason_codes'],true));
$bad=$good;$bad['pairs'][0]['candidate']['lcp_ms']=3500;$x=$eval($bad,$ctx);
$check('single_lcp_regression_blocks',$x['public_eligible']===false && in_array('lcp_regression',$x['reason_codes'],true));
$bad=$good;$bad['pairs'][0]['candidate']['js_errors']=1;$x=$eval($bad,$ctx);
$check('javascript_error_blocks',$x['public_eligible']===false && in_array('js_or_interaction_not_proven',$x['reason_codes'],true));
$bad=$good;$bad['pairs'][0]['candidate']['cls']=0.045;$x=$eval($bad,$ctx);
$check('cls_regression_blocks',$x['public_eligible']===false && in_array('cls_regression',$x['reason_codes'],true));
$bad=$good;$bad['pairs'][0]['visual_state']='not_verified';$x=$eval($bad,$ctx);
$check('missing_visual_parity_blocks',$x['public_eligible']===false && in_array('visual_parity_not_proven',$x['reason_codes'],true));
$bad=$good;$bad['pairs']=array_slice($bad['pairs'],0,2);$x=$eval($bad,$ctx);
$check('insufficient_sample_count_blocks',$x['public_eligible']===false && in_array('insufficient_cold_warm_viewport_coverage',$x['reason_codes'],true));
$bad=$good;$bad['candidate']='ucss_async';$x=$eval($bad,$ctx);
$check('ucss_dynamic_state_blocks',$x['public_eligible']===false && in_array('ucss_dynamic_states_not_proven',$x['reason_codes'],true));
$bad=$good;$bad['candidate']='ucss_async';$bad['dynamic_states_covered']='validated';$x=$eval($bad,$ctx);
$check('ucss_validated_positive_can_pass',($x['state']??'')==='passed_evidence');
$bad=$good;$bad['pairs'][0]['native']['fcp_ms']=900;$x=$eval($bad,$ctx);
$check('timing_invariant_blocks',$x['public_eligible']===false && in_array('invalid_browser_timing_or_cls',$x['reason_codes'],true));
$ctxBad=$ctx;$ctxBad['discovery_fresh']=false;$x=$eval($good,$ctxBad);
$check('stale_content_discovery_blocks',$x['public_eligible']===false && in_array('content_discovery_stale',$x['reason_codes'],true));
$ctxBad=$ctx;$ctxBad['live_runtime_mode']='off';$x=$eval($good,$ctxBad);
$check('runtime_disabled_blocks',$x['public_eligible']===false && in_array('public_runtime_not_enabled',$x['reason_codes'],true));
echo json_encode(['tests'=>count($checked),'passed'=>count(array_filter($checked)), 'all_passed'=>!in_array(false,$checked,true),'live_css_policy_state'=>$actual['state']],JSON_UNESCAPED_SLASHES)."\n";
