<?php
define('ABSPATH', __DIR__);
require __DIR__.'/../includes/class-mad4b-scp-aci01-recipe-gap.php';
function check($ok,$message){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$c='MAD4B_SCP_ACI01_Recipe_Gap';
$s=array('contract'=>'mad4b.aci01.semantic-recipe.v1','status'=>'NEEDS_EVIDENCE',
    'semantic_fingerprint_sha256'=>str_repeat('a',64),
    'mapping'=>array('profile_slug'=>'tour_product','profile_revision'=>5,
        'profile_authority_sha256'=>str_repeat('b',64)));
$d=array('contract'=>'mad4b.aci01.recipe-declaration.v1',
    'profile_slug'=>'tour_product','profile_revision'=>5,
    'profile_authority_sha256'=>str_repeat('b',64),
    'requirements'=>array('availability_source','verified_price_source','cancellation_terms'));
$r=$c::evaluate($s,$d,array());
check($r['status']==='NEEDS_EVIDENCE'&&count($r['missing_requirement_keys'])===3,'missing domain facts');
check($r['authorizing']===false&&$r['mutation_performed']===false,'never authorizes');
check($r['observed_receipt_count']===0,'no receipts');
check($r['candidate_sha256']===$c::evaluate($s,$d,array())['candidate_sha256'],'determinism');
check($c::evaluate($s,null,array())['status']==='NEEDS_EVIDENCE','missing recipe fails conservative');
check(in_array('reviewed_domain_recipe',$c::evaluate($s,null,array())['missing_requirement_keys'],true),'unreviewed marked');
$bad=$d;$bad['profile_revision']=6;
check($c::evaluate($s,$bad,array())['status']==='DENIED','cross revision');
$bad=$d;$bad['profile_authority_sha256']=str_repeat('c',64);
check($c::evaluate($s,$bad,array())['status']==='DENIED','cross authority hash');
$bad=$d;$bad['requirements'][]='availability_source';
check($c::evaluate($s,$bad,array())['status']==='DENIED','duplicate requirements');
$bad=$d;$bad['requirements']=array_fill(0,41,'fact');
check($c::evaluate($s,$bad,array())['status']==='DENIED','bounded');
$receipt=array('requirement'=>'verified_price_source','source_sha256'=>str_repeat('f',64),
    'semantic_sha256'=>str_repeat('a',64));
$partial=$c::evaluate($s,$d,array($receipt));
check(count($partial['missing_requirement_keys'])===2,'partial evidence');
check($partial['source_receipts_independently_certified']===false,'source observation never rights');
check($c::evaluate($s,$d,array($receipt,$receipt))['status']==='DENIED','duplicate receipt');
$receipt['semantic_sha256']=str_repeat('c',64);
check($c::evaluate($s,$d,array($receipt))['status']==='DENIED','wrong semantic');
$receipt['semantic_sha256']=str_repeat('a',64);
$receipt['requirement']='unrelated';
check($c::evaluate($s,$d,array($receipt))['status']==='DENIED','injected requirement');
$bad=$s;$bad['status']='READY';
check($c::evaluate($bad,$d,array())['status']==='DENIED','caller forged semantic readiness');

// Exercise the real private governed profile normalizer on bounded fixtures.
// This is a hermetic contract fixture; it does not apply any WordPress changes.
class WP_Error { public function __construct($code='', $message=''){} }
class MAD4B_SCP_Site_Profile {
    public static function site_uuid(){return '123e4567-e89b-42d3-a456-426614174000';}
}
require __DIR__.'/../includes/class-mad4b-scp-content-experience-profiles.php';
$normalizer=new ReflectionMethod('MAD4B_SCP_Content_Experience_Profiles',
    'normalize_aci01_recipe_variants');
$normalizer->setAccessible(true);
$variant=array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
    'brand_id'=>'brand-1','locale'=>'ar-EG','market'=>'eg',
    'requirements'=>array('verified_price_source','approved_brand_context'));
$normalized=$normalizer->invoke(null,array($variant));
check(is_array($normalized)&&$normalized[0]['market']==='EG',
    'normalizer canonicalizes exact market only');
check($normalized[0]['requirements']===array('approved_brand_context','verified_price_source'),
    'normalizer sorts fact obligations deterministically');
check($normalizer->invoke(null,array($variant,$variant)) instanceof WP_Error,
    'duplicate site brand locale market denied at profile plan');
$foreign=$variant;$foreign['site_uuid']='123e4567-e89b-42d3-a456-426614174999';
check($normalizer->invoke(null,array($foreign)) instanceof WP_Error,
    'cross-site profile write denied');
$wild=$variant;$wild['locale']='*';
check($normalizer->invoke(null,array($wild)) instanceof WP_Error,
    'no wildcard locale fallback');
$extra=$variant;$extra['unknown_permission']='write';
check($normalizer->invoke(null,array($extra)) instanceof WP_Error,
    'unrecognized recipe field denied');
$dupe=$variant;$dupe['requirements']=array('fact_qa','fact_qa');
check($normalizer->invoke(null,array($dupe)) instanceof WP_Error,
    'duplicate fact requirement denied at profile apply');
$large=array_fill(0,25,$variant);
check($normalizer->invoke(null,$large) instanceof WP_Error,
    'variant count bounded');
$bad=$variant;$bad['requirements']=array_fill(0,41,'fact_qa');
check($normalizer->invoke(null,array($bad)) instanceof WP_Error,
    'requirement count bounded');
$empty=$variant;$empty['requirements']=array();
check($normalizer->invoke(null,array($empty)) instanceof WP_Error,
    'empty recipe never approved');
check($normalizer->invoke(null,array())===array(),
    'legacy profiles with no recipes retain no-default behavior');

// Recipes must participate in the existing authority digest. A legacy
// profile without the optional key must retain its previous hash.
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require __DIR__.'/../includes/class-mad4b-scp-content-experience-governance.php';
$legacy_profile=array('slug'=>'article','revision'=>1,'post_type'=>'post',
    'enabled_helpers'=>array());
$legacy_sha=MAD4B_SCP_Content_Experience_Governance::authority_sha256($legacy_profile);
$legacy_copy=$legacy_profile;
check(MAD4B_SCP_Content_Experience_Governance::authority_sha256($legacy_copy)===$legacy_sha,
    'legacy absent recipe key remains hash-stable');
$with_recipe=$legacy_profile;
$with_recipe['aci01_recipe_variants']=array($normalized[0]);
$profile_sha=MAD4B_SCP_Content_Experience_Governance::authority_sha256($with_recipe);
check($profile_sha!==$legacy_sha,
    'introducing scoped recipe changes governed authority digest');
$with_recipe['authority_sha256']=$profile_sha;
check(MAD4B_SCP_Content_Experience_Governance::current_guard($with_recipe)===true,
    'matching recipe-bearing governed profile validates');
$with_recipe['aci01_recipe_variants'][0]['requirements'][]='injected_fact';
check(MAD4B_SCP_Content_Experience_Governance::current_guard($with_recipe) instanceof WP_Error,
    'unreviewed recipe tamper denied by authority fingerprint');
$legacy_profile['authority_sha256']=$legacy_sha;
check(MAD4B_SCP_Content_Experience_Governance::current_guard($legacy_profile)===true,
    'legacy profile remains current without forced migration');
echo "ACI01_RECIPE_GAP: PASS\n";
