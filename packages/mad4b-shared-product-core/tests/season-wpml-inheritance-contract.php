<?php
/** Read-only WPML season policy fallback contract (no WordPress installation required). */
declare(strict_types=1);
define('ABSPATH', __DIR__);
class WP_Term {
 public function __construct(public int $term_id, public string $taxonomy='season', public string $slug='winter') {}
}
class WP_Error {public function __construct(public string $code) {} }
function add_action(...$args):void {}
function apply_filters(string $name, $value, ...$args) {
 global $mockLanguage, $mockBidirectional;
 if($name==='wpml_default_language')return 'en';
 if($name==='wpml_current_language')return $mockLanguage;
 if($name==='wpml_object_id'){
  if(!$mockBidirectional)return $value;
  $codes=['en'=>2736,'fr'=>2742,'de'=>2738,'es'=>2740,'it'=>2739,'ar'=>2737];
  $id=(int)$value;
  if(!in_array($id,$codes,true))return false;
  return $codes[$args[2]??'en']??false;
 }
 return $value;
}
function get_term_meta(int $id, string $key, bool $single=true) {
 global $mockMeta;
 return $mockMeta[$id][$key]??'';
}
function get_term(int $id, string $taxonomy='') {
 return in_array($id,[2736,2742,2738,2740,2739,2737],true)?new WP_Term($id):null;
}
$mockLanguage='en';$mockBidirectional=true;
$mockMeta=[2736=>[
 'season_enabled'=>'1','season_mode'=>'travel',
 'season_start_month'=>'10','season_start_day'=>'1',
 'season_end_month'=>'4','season_end_day'=>'30',
 'season_cross_year'=>'1'
]];
require __DIR__.'/../mu-plugins/shared-product-core/pricing/taxonomy-pricing-context.php';
$checks=[];
foreach(['en'=>2736,'fr'=>2742,'de'=>2738,'es'=>2740,'it'=>2739,'ar'=>2737] as $lc=>$id){
 $mockLanguage=$lc;
 $checks[$lc.'_valid']=shared_product_season_term_matches_date(new WP_Term($id),'2026-11-15');
 $checks[$lc.'_outside']=!shared_product_season_term_matches_date(new WP_Term($id),'2026-07-15');
}
// Explicit translated disable wins over original enabled source.
$mockLanguage='fr';$mockMeta[2742]['season_enabled']='0';
$checks['explicit_disable']=!shared_product_season_term_matches_date(new WP_Term(2742),'2026-11-15');
unset($mockMeta[2742]);
$mockBidirectional=false;
$checks['unlinked_translation_closed']=!shared_product_season_term_matches_date(new WP_Term(2742),'2026-11-15');
$mockBidirectional=true;
$checks['wrong_taxonomy_closed']=!shared_product_season_term_matches_date(new WP_Term(2742,'tour_categories'),'2026-11-15');
$checks['invalid_date_closed']=!shared_product_season_term_matches_date(new WP_Term(2742),'2026-02-30');
$ok=!in_array(false,$checks,true);
echo json_encode(['status'=>$ok?'PASS':'FAIL','checks'=>$checks],JSON_UNESCAPED_UNICODE),PHP_EOL;
exit($ok?0:1);
