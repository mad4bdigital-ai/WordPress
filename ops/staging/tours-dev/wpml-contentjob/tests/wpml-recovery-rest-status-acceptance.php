<?php
/**
 * Read-only Staging acceptance for recovery REST error classification.
 * Execute using an explicitly authorized WP-CLI --user=26 on tours.dev.
 * This does NOT certify external OAuth or application-password authentication.
 */
defined('ABSPATH') || exit(1);
if (!defined('WP_CLI') || !WP_CLI
    || wp_get_environment_type()!=='staging'
    || rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'
    || get_current_user_id()!==26
    || !current_user_can('manage_options')
    || !MAD4B_SCP_Policy::can_mutate()) {
    WP_CLI::error('recovery_rest_status_wrong_authority_or_site');
}
$ids=[
    'fr'=>'89c69db1-d12e-4812-a198-bb9e3441e944',
    'de'=>'2eb24538-fab8-4cc0-b63c-5d2aaa42fbb9'
];
$keys=[
    'fr'=>'c6b4413e2acce49576cd590c561dfb01bdb8efe8a959c9143975279a1ea8ee5d',
    'de'=>'6568b4221002a1ce9b95286d7679b56bf449e5bc68746890d4abdddd33156197'
];
$snapshot=static function()use($ids,$keys){
    $outbox=MAD4B_Elementor_Localization_V1::outbox();
    $states=[];
    foreach($ids as $locale=>$id){
        $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
        if(is_wp_error($j)) WP_CLI::error('missing_native_job_'.$locale);
        $states[$locale]=[
            'native_state'=>$j['job']['state']??'missing',
            'outbox'=>$outbox[$keys[$locale]]??null
        ];
    }
    return $states;
};
$before=$snapshot();
if($before['fr']['native_state']!=='COMPLETED'
    ||$before['de']['native_state']!=='COMPLETED') {
    WP_CLI::error('hero_jobs_not_completed');
}
$call=static function($input){
    $request=new WP_REST_Request('POST','/mad4b-localization/v1/jobs/recover');
    $request->set_header('Content-Type','application/json');
    $request->set_body(wp_json_encode($input));
    $response=rest_do_request($request);
    $data=$response->get_data();
    return ['http'=>$response->get_status(),'code'=>$data['code']??null];
};
$invalid=$call([]);
$receipt=(array)get_option('mad4b_wpml_hero_publication_receipt_fr_v1',[]);
$payload=[
    'key'=>$keys['fr'],
    'expected_source_sha256'=>$before['fr']['outbox']['source_sha256']??'',
    'receipt_id'=>$receipt['receipt_id']??''
];
$completed=$call($payload);
$payload['receipt_id']='mad4b.wpml.hero.'.str_repeat('0',32);
$wrong_receipt_on_completed=$call($payload);
$after=$snapshot();
$checks=[
    $invalid['http']===400,
    $invalid['code']==='mad4b_localization_recovery_invalid_input',
    $completed['http']===409,
    $completed['code']==='mad4b_localization_recovery_not_eligible',
    $wrong_receipt_on_completed['http']===409,
    $wrong_receipt_on_completed['code']==='mad4b_localization_recovery_not_eligible',
    $before===$after,
    $after['fr']['native_state']==='COMPLETED',
    $after['de']['native_state']==='COMPLETED'
];
if(in_array(false,$checks,true)) WP_CLI::error('recovery_rest_status_regression_failed');
echo wp_json_encode([
    'contract'=>'mad4b.wpml.recovery-rest-status-acceptance.v1',
    'assertions_passed'=>count($checks),
    'invalid_input'=>$invalid,
    'completed_hero'=>$completed,
    'wrong_receipt_on_completed'=>$wrong_receipt_on_completed,
    'completed_jobs_unchanged'=>true,
    'external_authenticated_https_certified'=>false,
    'eligible_receipt_mismatch_certified'=>false,
    'at_utc'=>gmdate('c')
],JSON_UNESCAPED_SLASHES)."\n";
