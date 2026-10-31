<?php
define('ABSPATH',__DIR__);
$GLOBALS['registered']=array();
function add_action($hook,$callback,$priority=10) {$GLOBALS['registered'][$hook]=$callback;}
function add_filter($hook,$callback,$priority=10,$accepted=1) {$GLOBALS['registered'][$hook]=$callback;}
class MAD4B_SCP_Site_Profile {
    public static $env='staging';
    public static function current_environment(){return self::$env;}
    public static function configured(){return true;}
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
    public static function site_uuid(){return 'site-a';}
}
class MAD4B_SCP_Staging_Write_Grant_Reconciliation {
    public static function allowed_ability_providers(){
        return array('context/brand-draft-create'=>'core',
            'mad4b/provider-connection-refresh'=>'core');
    }
}
require dirname(__DIR__).'/mad4b-authority-reviews-classifier.php';
function check($c,$label){if(!$c){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
MAD4B_Authority_Reviews_Classifier::boot();
check(isset($GLOBALS['registered']['option_mad4b_authority_reconciliation_reviews_v1']),
    'native option filter registered');
$entry=function($subject) {
    return array('contract'=>'mad4b.authority-review-intake.v1',
        'site_uuid'=>'site-a','kind'=>'exact_grant','subject'=>$subject,
        'resolved'=>false,'state'=>'REVIEW_REQUIRED',
        'reason'=>'no_sealed_prior_grant_approval','authorization_granted'=>false);
};
$before=array('approved'=>$entry('context/brand-draft-create@core'),
    'new'=>$entry('mad4b/page-r1-publish-apply@core'),
    'identity'=>array('kind'=>'subject_delegation','state'=>'REVIEW_REQUIRED'),
    'resolved'=>array_merge($entry('mad4b/page-r1-publish-apply@core'),array('resolved'=>true)));
$after=MAD4B_Authority_Reviews_Classifier::classify($before);
check($after['approved']['source_reviewed']===true,'reviewed source recorded');
check($after['approved']['state']==='REVIEW_REQUIRED','source approved does not grant authority');
check($after['new']['source_reviewed']===false,'unknown ability rejected by source');
check($after['new']['state']==='SOURCE_POLICY_REVIEW_REQUIRED','missing source policy gets review state');
check($after['new']['reason']==='capability_provider_outside_reviewed_source_policy','specific reason');
check($after['identity']===$before['identity'],'identity review unaffected');
check($after['resolved']===$before['resolved'],'resolved records unaffected');
check(MAD4B_Authority_Reviews_Classifier::classify($after)===$after,'idempotent');
check($before['new']['state']==='REVIEW_REQUIRED','source input unmodified');
MAD4B_SCP_Site_Profile::$env='production';
check(MAD4B_Authority_Reviews_Classifier::classify($before)===$before,'production untouched');
echo "PASS: source-backed classification with no grant or identity changes (10 checks)\n";
