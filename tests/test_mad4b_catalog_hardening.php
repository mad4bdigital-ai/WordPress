<?php
/** Isolated mutation-oriented regression for catalog data epochs and fingerprints. */
define('ABSPATH','/isolated');
$GLOBALS['options']=[];$GLOBALS['hooks']=[];$checks=0;
function check($ok,$name){global $checks;$checks++;if(!$ok)throw new RuntimeException('FAILED_'.$name);}
function add_action($name,$callback,$priority=10,$argc=1){$GLOBALS['hooks'][$name][]=$callback;}
function get_option($name,$default=null){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['options'][$name]=$value;return true;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function mcfm_get_feeds(){return [
 ['slug'=>'mad4b-managed-tours-internal','object_type'=>'post_type','object_key'=>'tours-and-activities'],
 ['slug'=>'mad4b-managed-destinations-internal','object_type'=>'taxonomy','object_key'=>'destinations'],
 ['slug'=>'legacy-public-feed','object_type'=>'post_type','object_key'=>'post'],
];}
function mcfm_normalize_feed($f){return array_merge(['mapping'=>[],'delimiter'=>',','default_format'=>'csv','language_mode'=>'all'], $f);}
function post_type_exists($type){return in_array($type,['tours-and-activities','post'],true);}
function taxonomy_exists($type){return $type==='destinations';}
function mcfm_update_feeds($rows){}
function mcfm_iterate_feed_rows($f,$l,$cb){}
function sanitize_key($key){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$key));}
function get_post_type($id){return in_array($id,[10,11],true)?'tours-and-activities':'post';}
class WP_Term{public function __construct(public string $taxonomy){}}
function get_term($id){return $id===20?new WP_Term('destinations'):new WP_Term('post_tag');}
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-catalog-data-epoch.php';
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-catalog-studio.php';
$epoch='MAD4B_Catalog_Data_Epoch_V1';$studio='MAD4B_Catalog_Studio_V1';
$f=mcfm_normalize_feed(mcfm_get_feeds()[0]);
check(isset($GLOBALS['hooks']['updated_post_meta']),'POST_META_HOOK_REGISTERED');
check(isset($GLOBALS['hooks']['updated_term_meta']),'TERM_META_HOOK_REGISTERED');
check(isset($GLOBALS['hooks']['shutdown']),'BATCHED_FLUSH_REGISTERED');
$initial=$studio::fingerprint($f);
$changed=$f;$changed['delimiter']="\t";
check($studio::fingerprint($changed)!==$initial,'DELIMITER_INVALIDATES_FINGERPRINT');
$changed=$f;$changed['xml_variant']='atom';
check($studio::fingerprint($changed)!==$initial,'XML_VARIANT_INVALIDATES_FINGERPRINT');
$changed=$f;$changed['label']='Another label';
check($studio::fingerprint($changed)!==$initial,'PROFILE_OPTIONS_INVALIDATE_FINGERPRINT');
$epoch::post_meta(1,300);
$epoch::flush();
check($studio::fingerprint($f)===$initial,'UNRELATED_POST_NO_INVALIDATION');
$epoch::post_meta(1,10);
$epoch::post_meta(1,11);
check($studio::fingerprint($f)===$initial,'BATCH_UPDATES_AT_SHUTDOWN');
$epoch::flush();
check($studio::fingerprint($f)!==$initial,'TOUR_PRICE_META_INVALIDATES');
$destination=mcfm_normalize_feed(mcfm_get_feeds()[1]);$before=$studio::fingerprint($destination);
$epoch::term_meta(12,20);
$epoch::flush();
check($studio::fingerprint($destination)!==$before,'DESTINATION_TERM_META_INVALIDATES');
$after=$studio::fingerprint($destination);
$epoch::term_meta(14,999);
$epoch::flush();
check($studio::fingerprint($destination)===$after,'UNRELATED_TERM_NOT_INVALIDATE');
$before=$studio::fingerprint($f);
$epoch::post(10);
$epoch::flush();
check($studio::fingerprint($f)!==$before,'SAVE_POST_INVALIDATES');
$before=$studio::fingerprint($f);
$epoch::object_terms(10,[],[],'destinations');
$epoch::flush();
check($studio::fingerprint($f)!==$before,'TOUR_TAXONOMY_CHANGED_INVALIDATES');
$before=$studio::fingerprint($destination);
$epoch::edited_term(20,20,'destinations');
$epoch::flush();
check($studio::fingerprint($destination)!==$before,'TERM_EDIT_INVALIDATES');
$GLOBALS['options'][$studio::STORE]=[$f['slug']=>[
 'checked_at'=>gmdate('c'),'fingerprint'=>$studio::fingerprint($f),
 'status'=>'READY_INTERNAL','sample_count'=>5]];
check($studio::get_state($f)['status']==='READY_INTERNAL','CURRENT_RECEIPT_ACCEPTED');
$old=$GLOBALS['options'][$studio::STORE];
$GLOBALS['options'][$studio::STORE][$f['slug']]['checked_at']=gmdate('c',time()-3700);
check($studio::get_state($f)['status']==='DRAFT','EXPIRED_RECEIPT_INVALID');
$GLOBALS['options'][$studio::STORE]=$old;
$epoch::post_meta(10,10);$epoch::flush();
check($studio::get_state($f)['status']==='DRAFT','PRICE_META_INVALIDATES_READY');
check($studio::validate_structure(array_merge($f,['mapping'=>[
 ['column'=>'api_token','source_type'=>'meta','source'=>'secret_value']]]))['issues']!==[],
 'SECRET_OUTPUT_COLUMN_REJECTED');
$evidence=$studio::commercial_sample(array_merge($f,['mapping'=>[
 ['column'=>'weighted_price'],['column'=>'google_price_ready'],['column'=>'google_checks_missing']
]]),[['weighted_price'=>'102.00','google_price_ready'=>'0','google_checks_missing'=>'fees_and_taxes,availability','price_state'=>'price_reference_available'],
    ['weighted_price'=>'','google_price_ready'=>'0','google_checks_missing'=>'price,checkout_match','price_state'=>'price_on_request']]);
check($evidence['status']==='action_required','COMMERCIAL_UNVERIFIED_STATUS');
check($evidence['priced']===1,'WEIGHTED_PRICE_DISTINCT_FROM_VERIFIED');
check($evidence['price_on_request']===1,'ON_REQUEST_COUNTS');
check(($evidence['missing_checks']['fees_and_taxes']??0)===1,'FEES_CHECK_RECORDED');
check($evidence['provider_submission_ready']===false,'NO_GOOGLE_APPROVAL_INFERRED');
echo json_encode(['suite'=>'mad4b.catalog-studio.hardening','checks_passed'=>$checks,
 'covers'=>['delimiter','data_epoch','price_meta','taxonomy','receipt_ttl','private_fields']],JSON_UNESCAPED_SLASHES).PHP_EOL;
