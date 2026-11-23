<?php
declare(strict_types=1);
define('ABSPATH','/test/');
define('WP_CLI',true);
$GLOBALS['options']=[
 'home'=>'https://tours.dev.mad4b.com',
 'icl_sitepress_settings'=>['active_languages'=>['en','es','de','fr','ar','it'],'default_language'=>'en'],
 'page_on_front'=>2,
 'page_for_posts'=>33936
];
$GLOBALS['hooked']=[];
$GLOBALS['writes']=[];
// The original unit fixture now exercises an advisory-lock-protected writer.
// Simulate one WordPress DB session without touching a real website/database.
class MAD4BCSSCoverageMockWpdb {
    public string $prefix='wp_';
    public string $options='wp_options';
    public function prepare(string $sql,...$args): string {return $sql;}
    public function get_var(string $sql): ?string {
        if (str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK'))return '1';
        return null;
    }
    public function query(string $sql): int {throw new RuntimeException('unplanned-test-SQL');}
}
$GLOBALS['wpdb']=new MAD4BCSSCoverageMockWpdb();
function wp_cache_delete($key,$group='options'){return true;}
function wp_parse_url($value,$part=null){return parse_url($value,$part);}
function get_option($key,$fallback=null){return $GLOBALS['options'][$key]??$fallback;}
function update_option($key,$value,$autoload=false){$GLOBALS['writes'][]=$key;$GLOBALS['options'][$key]=$value;return true;}
function update_post_meta($id,$key,$value){$GLOBALS['meta_writes'][]=[$id,$key,$value];return true;}
function add_action($name,$callback,$priority=10,$arity=1){$GLOBALS['hooked'][$name]=$callback;}
function get_post($id){return (object)['ID'=>$id,
 'post_type'=>$GLOBALS['post_types'][$id]??'tours-and-activities'];}
function get_term($id){return (object)['term_id'=>$id,'taxonomy'=>'tour-types'];}
function is_wp_error($v){return false;}
function wc_get_page_id($type){return ['cart'=>91,'checkout'=>92,'myaccount'=>93,'terms'=>94][$type]??-1;}
function apply_filters($hook,$value,...$rest){
 if($hook==='wpml_object_id' && $value===33936 && ($rest[2]??'')==='es')return 33940;
 return $value;
}
require '/tmp/mad4b-public-critical-coverage-lint-20261117.php';
function check($label,$value){if(!$value)throw new RuntimeException('FAILED '.$label);$GLOBALS['checks_count']=($GLOBALS['checks_count']??0)+1;echo "PASS ".$label."\n";}
check('site enrolled',MAD4B_Public_Critical_CSS_Coverage_V1::authorized());
check('tour content eligible',MAD4B_Public_Critical_CSS_Coverage_V1::supported_type('tours-and-activities'));
check('private order cannot enter',!MAD4B_Public_Critical_CSS_Coverage_V1::supported_type('tour_orders'));
check('checkout denied',!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/es/checkout/'));
$ref = new ReflectionMethod('MAD4B_Public_Critical_CSS_Coverage_V1','page_allowed');
check('WordPress main blog index is not a singular Critical CSS page',$ref->invoke(null,33936,'en')===false);
check('WPML translated posts index likewise not a singular CSS page',$ref->invoke(null,33940,'es')===false);
check('ordinary non-commerce WordPress page remains eligible',$ref->invoke(null,21338,'en')===true);
check('translated checkout denied',!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/fr/paiement/'));
check("translated checkout denied",!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path("/fr/paiement/"));
check('order payment denied',!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/order-pay/3228/'));
check('WordPress JSON denied',!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/wp-json/'));
check('legitimate tour URL allowed',MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/tours-and-activities/egypt-nile-cruise/'));
check('valid translated page allowed',MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/fr/properties/cruise-ship/'));
check('doubled-slash denied',!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/fr//properties/'));
check('query string denied',!MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path('/?price=120'));
MAD4B_Public_Critical_CSS_Coverage_V1::boot();
check('native WP content hooks registered',isset($GLOBALS['hooked']['post_updated'],$GLOBALS['hooked']['updated_post_meta'],$GLOBALS['hooked']['updated_option']));
$unchanged=(object)['post_type'=>'tours-and-activities','post_status'=>'publish','post_content'=>'old','post_title'=>'Tour'];
MAD4B_Public_Critical_CSS_Coverage_V1::post_updated(77,$unchanged,clone $unchanged);
check('unchanged post does not invalidate',$GLOBALS['writes']===[]);
MAD4B_Public_Critical_CSS_Coverage_V1::post_updated(77,(object)['post_type'=>'tours-and-activities','post_status'=>'publish','post_content'=>'new','post_title'=>'Tour'],$unchanged);
check('changed tour records targeted dependency',(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===1);
check('changed tour does not invalidate unrelated home',!isset($GLOBALS['options']['mad4b_critical_css_home_dirty_v1']));
check('changed tour records exact post revision',count($GLOBALS['meta_writes']??[])===1);
check('changed tour invalidates only listing cohort',isset($GLOBALS['options']['mad4b_ccss_listing_generation_v1']));
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
$oldRevision=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$passwordAfter=clone $unchanged;
$passwordAfter->post_password='temporary-public-post-password';
MAD4B_Public_Critical_CSS_Coverage_V1::post_updated(77,$passwordAfter,$unchanged);
check('password protection transition invalidates old public CSS certificate',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']>$oldRevision);

MAD4B_Public_Critical_CSS_Coverage_V1::option_updated('icl_sitepress_settings',['active_languages'=>['en']],['active_languages'=>['en']]);
check('unrelated WPML option change ignored',!isset($GLOBALS['options']['mad4b_critical_css_home_dirty_v1']));
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated('icl_sitepress_settings',['active_languages'=>['en']],['active_languages'=>['en','es']]);
check('WPML language changes invalidate home',(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']===1);
check('cache contract stays event-only',!isset($GLOBALS['options']['mad4b_perf_critical_css_all_public_v1']));
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
$before=$GLOBALS['options']['mad4b_ccss_listing_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(1,77,'_thumbnail_id',458);
check('tour featured image triggers targeted recertification',
 $GLOBALS['options']['mad4b_ccss_listing_generation_v1']>$before);
$before=$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(1,77,'_edit_lock','random');
check('WordPress editor lock changes do not invalidate CSS proof',
 $GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before);
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(1,77,'rate_period',149);
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
check('price/rate field change triggers event generation',
 $GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']>$before);
$before=$GLOBALS['options']['mad4b_ccss_listing_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::term_meta_updated(1,625,'destination_image',92);
check('taxonomy featured image invalidates affected listing cohort',
 $GLOBALS['options']['mad4b_ccss_listing_generation_v1']>$before);
$before=$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
MAD4B_Public_Critical_CSS_Coverage_V1::term_meta_updated(1,625,'_edit_lock','123');
check('irrelevant taxonomy editor metadata does not recertify',
 $GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before);
// Global first-paint/header invalidation is event driven from the native
// activation authorities, not from periodic Chromium reruns.
check('native activation and menu hooks enrolled',
 isset($GLOBALS['hooked']['wp_update_nav_menu'],
       $GLOBALS['hooked']['added_option'],$GLOBALS['hooked']['deleted_option']));
$oldHome=(int)($GLOBALS['options']['mad4b_critical_css_home_dirty_v1']??0);
$oldGlobal=(int)($GLOBALS['options']['mad4b_ccss_global_generation_v1']??0);
$oldListing=(int)($GLOBALS['options']['mad4b_ccss_listing_generation_v1']??0);
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated(
 'service-activation',['tours'=>0],['tours'=>1]);
check('JetEngine service activation requalifies shared homepage header',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome
 && (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']>$oldGlobal
 && (int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1']>$oldListing);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated(
 'destination-activation',['countries'=>['EG']],['countries'=>['EG','JO']]);
check('destination activation invalidates first screen only upon change',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated(
 'product_navigation_sources',['tours'=>1],['properties'=>1]);
check('product navigation topology update invalidates old browser proof',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated(
 'look-and-feel',['header'=>'A'],['header'=>'B']);
check('brand header look and feel changes invalidate first screen',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated(
 'look-and-feel',['header'=>'A'],['header'=>'A']);
MAD4B_Public_Critical_CSS_Coverage_V1::option_updated(
 'unrelated-private-payment-field',1,2);
check('unchanged visual settings and private payment fields do not trigger CSS QA',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']===$oldHome);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::option_added('service-activation',['active'=>1]);
check('newly added service activation source triggers first screen requalification',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::option_deleted('destination-activation');
check('deleted destination activation source also invalidates old proof',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
$GLOBALS['post_types'][901]='nav_menu_item';
$oldGlobal=(int)$GLOBALS['options']['mad4b_ccss_global_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(1,901,'_menu_item_url','/es/egipto/');
check('edited native menu URL invalidates company header',
 (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']>$oldGlobal);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
$oldGlobal=(int)$GLOBALS['options']['mad4b_ccss_global_generation_v1'];
$oldNav=(object)['post_type'=>'nav_menu_item','post_status'=>'publish',
 'post_title'=>'Egypt','post_content'=>'','menu_order'=>1];
$newNav=clone $oldNav;$newNav->menu_order=2;
MAD4B_Public_Critical_CSS_Coverage_V1::post_updated(901,$newNav,$oldNav);
check('native menu reordering invalidates first screen',
 (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']>$oldGlobal);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::menu_updated(11);
check('native WordPress menu save triggers first screen change event',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::term_updated(665,1,'destinations');
check('destination category rename invalidates header and home proof',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::term_updated(14,2,'nav_menu');
check('navigation taxonomy edit invalidates cached Elementor header',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
check('new WordPress publication and taxonomy membership hooks registered',
 isset($GLOBALS['hooked']['wp_after_insert_post'],
       $GLOBALS['hooked']['deleted_post'],
       $GLOBALS['hooked']['set_object_terms'],
       $GLOBALS['hooked']['deleted_post_meta'],
       $GLOBALS['hooked']['deleted_term_meta'],
       $GLOBALS['hooked']['delete_term']));
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
$oldList=(int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::post_inserted(977,
 (object)['post_type'=>'tours-and-activities','post_status'=>'publish'],false,null);
check('first publication invalidates affected listing instead of being missed',
 (int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1']>$oldList);
$oldList=(int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::post_inserted(978,
 (object)['post_type'=>'tours-and-activities','post_status'=>'draft'],false,null);
check('unpublished draft cannot trigger expensive browser QA',
 (int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1']===$oldList);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
$oldGlobal=(int)$GLOBALS['options']['mad4b_ccss_global_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::post_inserted(979,
 (object)['post_type'=>'nav_menu_item','post_status'=>'publish'],false,null);
check('new native header menu item invalidates old first paint',
 (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']>$oldGlobal);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
$oldGlobal=(int)$GLOBALS['options']['mad4b_ccss_global_generation_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::post_deleted(979,
 (object)['post_type'=>'nav_menu_item','post_status'=>'publish']);
check('deleted header menu item also invalidates old certificate',
 (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']>$oldGlobal);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
$oldRoute=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
MAD4B_Public_Critical_CSS_Coverage_V1::terms_set(77,[2,3],[3,2],'destinations',false,[2,3]);
check('unchanged destination membership does not invalidate browser proof',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$oldRoute);
MAD4B_Public_Critical_CSS_Coverage_V1::terms_set(77,[2,5],[2,5],'destinations',false,[2,3]);
check('changed published tour destination membership invalidates its source',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']>$oldRoute);
$oldHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
MAD4B_Public_Critical_CSS_Coverage_V1::terms_set(979,[11],[11],'nav_menu',false,[]);
check('changed menu taxonomy membership invalidates shared header',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']>$oldHome);
// Hermetic simulation of 18 items x 9 native menu-meta fields in one
// WordPress request. The first invalidation is immediate, and a final
// shutdown invalidation protects any mid-request CSS certificate.
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
$before=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$beforeGlobal=(int)$GLOBALS['options']['mad4b_ccss_global_generation_v1'];
$beforeHome=(int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1'];
for ($item=26127;$item<26145;$item++) {
    $GLOBALS['post_types'][$item]='nav_menu_item';
    for ($field=0;$field<9;$field++) {
        MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(
          1000+$field,$item,'_menu_item_field_'.$field,'value_'.$field);
    }
}
// Additional native menu entrypoints during that same request cannot
// trigger extra global generations, while the final shutdown still does.
$changedNav=(object)['post_type'=>'nav_menu_item','post_status'=>'publish',
    'post_title'=>'Egypt','post_content'=>'','menu_order'=>2];
$previousNav=clone $changedNav;$previousNav->menu_order=1;
MAD4B_Public_Critical_CSS_Coverage_V1::post_updated(26127,$changedNav,$previousNav);
MAD4B_Public_Critical_CSS_Coverage_V1::menu_updated(11);
MAD4B_Public_Critical_CSS_Coverage_V1::terms_set(26127,[11],[11],'nav_menu',false,[]);
check('mixed native menu entrypoints in one request do not create extra invalidations',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+1);
check('18 menu items x9 fields yield one immediate generation',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+1
 && (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']===$beforeGlobal+1);
check('first menu change invalidates header immediately',
 (int)$GLOBALS['options']['mad4b_critical_css_home_dirty_v1']===$beforeHome+1);
check('WordPress shutdown hook was registered',
 isset($GLOBALS['hooked']['shutdown']));
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
check('final menu mark invalidates any intermediate CSS certification',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+2
 && (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']===$beforeGlobal+2);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
check('repeated shutdown is idempotent',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+2);
$after=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(1,26127,'_menu_item_url','/tours/');
check('next separate request immediately invalidates again',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$after+1);
MAD4B_Public_Critical_CSS_Coverage_V1::navigation_flushed();
check('next request finishes with its own final invalidation',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$after+2);
// Fresh separate-source operation. Repeated Property updates for one
// hotel must preserve first and final listing+post-route invalidation
// without 20 repeated dirty generations and without touching global/home.
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
$before=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$beforeList=(int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1'];
$beforeGlobal=(int)$GLOBALS['options']['mad4b_ccss_global_generation_v1'];
$GLOBALS['post_types'][35896]='properties';
for ($field=0;$field<25;$field++) {
    MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(
       20+$field,35896,'_hotel_gallery_image_'.$field,17118+$field);
}
check('25 same-hotel Elementor/JetEngine media changes immediately dirty once',
  (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+1);
check('first hotel meta event immediately invalidates its exact original listing',
  (int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1']===$before+1);
check('property update never accidentally invalidates global header',
  (int)$GLOBALS['options']['mad4b_ccss_global_generation_v1']===$beforeGlobal);
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
check('final 25-event hotel update marks changed source and listing again',
  (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+2
  && (int)$GLOBALS['options']['mad4b_ccss_listing_generation_v1']===$before+2);
check('subsequent content flush is idempotent',
  (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+2);
$before=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$GLOBALS['post_types'][35897]='properties';
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(100,35897,'hotel_image_id',500);
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
check('one-edit Property save remains original single generation',
  (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+1);
$before=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$GLOBALS['post_types'][60445]='tours-and-activities';
for($i=0;$i<13;$i++) {
 MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta($i+1,60445,'tour_hero_image_'.$i,1000+$i);
}
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
check('13 tour visual edits preserve final route proof with two events',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+2);
$before=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$GLOBALS['post_types'][60446]='tours-and-activities';
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(1,60446,'tour_hero_image',1000);
MAD4B_Public_Critical_CSS_Coverage_V1::changed_meta(2,60445,'tour_hero_image',1001);
MAD4B_Public_Critical_CSS_Coverage_V1::public_post_flushed();
check('two independent tours preserve separate source revisions',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+2);
$before=(int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation'];
$GLOBALS['post_types'][18888]='page';
MAD4B_Public_Critical_CSS_Coverage_V1::post_inserted(18888,
 (object)['post_type'=>'page','post_status'=>'publish'],false,null);
check('ordinary public page creation still invalidates original page route',
 (int)$GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']===$before+1);
echo "PASS ".$GLOBALS['checks_count']."/".$GLOBALS['checks_count']." dynamic public coverage route and dependency event invariants\n";
