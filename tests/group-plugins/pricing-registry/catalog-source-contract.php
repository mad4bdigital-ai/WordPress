<?php
// Synthetic WordPress fixtures, no site, DB, pricing or network.
define('ABSPATH', __DIR__);
class WP_Error{} class WP_Term {public function __construct(public int $term_id, public string $name){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap,...$args){return ($GLOBALS['admin']??true)&&($cap!=='edit_post'||$args[0]!==91);}
function get_posts($args){if($args['meta_key']!==MAD4B_Package_Stage_Rate_Guard_V1::SOURCE)throw new RuntimeException('wrong source');return [71,72,73,91,99];}
function get_post_status($id){return $id===99?'draft':'publish';}
function get_terms($args){if($args['taxonomy']!=='package_levels')throw new RuntimeException('wrong taxonomy');return [new WP_Term(31,'Standard'),new WP_Term(32,'Premium'),new WP_Term(33,'Premium'),new WP_Term(34,'translated')];}
function get_term($id,$tax){return match($id){31=>new WP_Term(31,'Standard'),32=>new WP_Term(32,'Premium'),33=>new WP_Term(33,'Premium'),default=>null};}
class MAD4B_Package_Stage_Rate_Guard_V1 {const SOURCE='_native_source'; public static function canonicalTour($id){return $id===72?71:$id;} public static function applies($id){return $id!==73;} public static function canonicalTier($id){return $id===34?31:$id;}}
require dirname(__DIR__,3).'/wp-content/plugins/mad4b-pricing-registry/includes/package-component-readiness.php';
function must($v,$label){if(!$v)throw new RuntimeException($label);}
$r=MAD4B_Package_Component_Readiness_V1::catalog();
must($r['tours']===[71],'deduplication and publish/package/edit scope');
must($r['tiers']===[31=>'Standard',32=>'Premium (#32)',33=>'Premium (#33)'],'native canonical tiers and duplicate names');
$GLOBALS['admin']=false;must(MAD4B_Package_Component_Readiness_V1::catalog()===['tours'=>[],'tiers'=>[]],'unauthorized denied');
echo "PACKAGE_NATIVE_CATALOG: PASS 3\n";
