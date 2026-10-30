<?php
require __DIR__.'/company-clone-gate.php';
$policy = json_decode(file_get_contents(__DIR__.'/field-sources.json'), true);
if (!is_array($policy)) { fwrite(STDERR, "POLICY_INVALID\n"); exit(2); }
$context = [
 'mode'=>'dry_run', 'source_snapshot_sha256'=>$policy['jetengine_options_contract']['source_sha256'],
 'source_site_uuid'=>MAD4B_WoVacation_Company_Clone_Gate::SOURCE_SITE,
 'source_host'=>MAD4B_WoVacation_Company_Clone_Gate::SOURCE_HOST,
 'source_environment'=>'staging', 'target_environment'=>'staging',
 'target_site_uuid'=>'12345678-1234-4234-9234-123456789abc'
];
$assert = static function($name,$ok) {
 if (!$ok) { echo "FAIL $name\n"; exit(1); }
 echo "PASS $name\n";
};
$select = static function($p,$f) {return ['page'=>$p,'field'=>$f];};
$valid=[
 $select('contact-and-help-info','support_group_url_aria_label'),
 $select('contact-and-help-info','site_email'),
 $select('look-and-feel','home_page_header_image'),
 $select('contact-and-help-info','support_chat_url')
];
$r=MAD4B_WoVacation_Company_Clone_Gate::plan($policy,$context,$valid);
$assert('four categories preview, no writes',($r['status']??'')==='requires_company_provisioning' && count($r['decisions'])===4 &&
       !$r['can_apply'] && $r['records_copied']===0 && !$r['mutation_performed']);
$assert('definition key only',$r['decisions'][0]['decision']==='definition_key_only');
$assert('company contact not inherited',$r['decisions'][1]['decision']==='new_company_value_required');
$assert('media cannot inherit old attachment ID',$r['decisions'][2]['decision']==='new_company_licensed_media_required');
$assert('provider must reconnect',$r['decisions'][3]['decision']==='new_provider_connection_required');
$denied=static function($case,$ctx,$selected,$pol) use ($assert) {
 $x=MAD4B_WoVacation_Company_Clone_Gate::plan($pol,$ctx,$selected);
 $assert($case,($x['status']??'')==='blocked' && !$x['can_apply'] && !$x['mutation_performed']);
};
$denied('unclassified field blocked',$context,[$select('look-and-feel','__unknown__')],$policy);
$denied('explicit review field blocked',$context,[$select('contact-and-help-info','card-as-action1')],$policy);
$denied('source field value rejected',$context,[['page'=>'contact-and-help-info','field'=>'site_email','value'=>'old@example.invalid']],$policy);
$denied('old source UUID denied',array_merge($context,['source_site_uuid'=>'4ef86619-35a5-4690-aa3e-6f298994965e']),$valid,$policy);
$denied('same site UUID denied',array_merge($context,['target_site_uuid'=>MAD4B_WoVacation_Company_Clone_Gate::SOURCE_SITE]),$valid,$policy);
$denied('production target denied',array_merge($context,['target_environment'=>'production']),$valid,$policy);
$denied('non-dry-run denied',array_merge($context,['mode'=>'apply']),$valid,$policy);
$denied('source snapshot drift denied',array_merge($context,['source_snapshot_sha256'=>str_repeat('0',64)]),$valid,$policy);
$denied('unknown page denied',$context,[$select('unknown_options_page','site_email')],$policy);
$denied('duplicate field denied',$context,[$valid[0],$valid[0]],$policy);
$denied('empty selection denied',$context,[],$policy);
$damaged=$policy;$damaged['jetengine_options_contract']['pages']['look-and-feel']['field_count']=0;
$denied('incomplete policy denied',$context,[$select('look-and-feel','home_page_header_image')],$damaged);
$altered=$policy;
$altered['jetengine_options_contract']['pages']['contact-and-help-info']['field_policy']['site_email']='SHARED_DEFINITION';
$denied('signed classification digest blocks policy tampering',$context,[$select('contact-and-help-info','site_email')],$altered);
echo "RESULT WO_VACATION_COMPANY_CLONE_PREVIEW_TESTS_PASS; no_runtime_hook=true; mutation_performed=false\n";
