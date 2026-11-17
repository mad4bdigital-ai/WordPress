<?php
/**
 * Isolated WordPress source-only footer/asset review acceptance.
 * Usage: php tests/footer-logo-embedded-slogan-priority-hermetic.php /src/<footer-module.php>
 * No DB, public HTTP, filesystem writes outside ephemeral /tmp, or approval.
 */
declare(strict_types=1);
define('ABSPATH', '/fixture/');
$asset=tempnam(sys_get_temp_dir(),'mad4b-logo-slogan-');
file_put_contents($asset, 'approved-logo-bytes-v1');
$GLOBALS['logo_file']=$asset;
$GLOBALS['checks']=0;
$GLOBALS['purges']=[];
$GLOBALS['logo_image']=true;
$GLOBALS['language']='en';
$GLOBALS['can_edit']=true;
$GLOBALS['attachment_meta']=[];
$GLOBALS['look']=[
    'footer_logo_inverse'=>'12',
    'mad4b_brand_logo_media'=>'12',
    'mad4b_brand_logo_inverse_media'=>'12',
    'mad4b_logo_artwork_signoff'=>'MORE THAN A TRIP ... A WAY OF LIFE.',
];
function add_filter(...$args){}
function add_action(...$args){}
function do_action($name,...$args){if($name==='mad4b/public_html/safe_purge_all')$GLOBALS['purges'][]=$args;}
function add_shortcode(...$args){}
function absint($id){return abs((int)$id);}
function current_user_can($cap,$id=0){return $GLOBALS['can_edit'];}
function get_option($key,$default=null){
    if($key==='look-and-feel')return $GLOBALS['look'];
    if($key==='elementor_active_kit')return 7;
    return $default;
}
function get_post_type($id){return in_array((int)$id,[12,13],true)?'attachment':null;}
function get_post_mime_type($id){return get_post_type($id)==='attachment'?'image/png':'';}
function wp_get_attachment_image($id,$size,$icon=false,$attr=[]){
    return $GLOBALS['logo_image']?'<img alt="Wayfa Tours" data-logo-id="'.(int)$id.'">':'';
}
function get_attached_file($id){return $GLOBALS['logo_file'];}
function get_post_meta($id,$key,$single=true){
    if((int)$id===7 && $key==='_elementor_page_settings')
        return ['site_description'=>'From Someday to Somewhere'];
    return $GLOBALS['attachment_meta'][(int)$id][$key]??'';
}
function update_post_meta($id,$key,$value){$GLOBALS['attachment_meta'][(int)$id][$key]=$value;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['attachment_meta'][(int)$id][$key]);return true;}
function company_brand_context(){return ['media_ids'=>['logo_default'=>12,'logo_inverse'=>12]];}
function get_bloginfo($field){return $field==='name'?'Wayfa Tours':'Alternate tagline';}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function home_url($s){return 'https://example.invalid'.$s;}
function apply_filters($hook,$value,...$args){
    if($hook==='wpml_current_language')return $GLOBALS['language'];
    if($hook==='wpml_translate_single_string' && $GLOBALS['language']==='es')
        return 'De Algún Día a Algún Lugar';
    return $value;
}
function check($v,$msg){if(!$v){fwrite(STDERR,"FAIL: $msg\n");exit(1);}$GLOBALS['checks']++;echo "PASS $msg\n";}
$module=$argv[1]??'';
check(is_file($module),'exact module supplied');
require $module;
check(class_exists('MAD4B_Footer_LookFeel',false),'first-party footer owner loaded');
$render=static function(){return MAD4B_Footer_LookFeel::brand();};
$show_text=static function($html){return strpos($html,'mad4b-footer-kit-tagline')!==false;};
$html=$render();
check(!$show_text($html) && str_contains($html,'data-logo-id="12"'),
      'approved artwork signoff wins even over a different textual slogan');
$GLOBALS['language']='es';
check(!$show_text($render()),'WPML language preserves logo-owned priority');
$GLOBALS['language']='en';
$GLOBALS['look']['mad4b_logo_artwork_signoff']='';
check($show_text($render()),'without reviewed embedded evidence legacy text remains');
$media=MAD4B_Footer_LookFeel::logo_slogan_media_fields([], (object)['ID'=>12]);
check(isset($media['mad4b_logo_slogan_presence'])
    && str_contains($media['mad4b_logo_slogan_presence']['html'],'Slogan included in logo image'),
    'brand logo Media Library has a reviewer classification');
check(!isset(MAD4B_Footer_LookFeel::logo_slogan_media_fields([],(object)['ID'=>13])['mad4b_logo_slogan_presence']),
    'unselected image does not pollute media reviewer UI');
$save=static function($value){
    MAD4B_Footer_LookFeel::save_logo_slogan_media_field(['ID'=>12],
        ['mad4b_logo_slogan_presence'=>$value]);
};
$save('embedded');
check(count($GLOBALS['purges'])===1,'logo review triggers exactly one safe public HTML purge');
$save('embedded');
check(count($GLOBALS['purges'])===1,'unchanged logo review does not trigger redundant purge');
$e=get_post_meta(12,'_mad4b_brand_logo_slogan_review_v1',true);
check(is_array($e) && $e['status']==='embedded'
    && hash_equals(hash_file('sha256',$GLOBALS['logo_file']),$e['file_sha256']),
    'review records exact image SHA256');
check(!$show_text($render()),'reviewed image-owned slogan beats text');
file_put_contents($GLOBALS['logo_file'],'replaced-unreviewed-logo-file');
check($show_text($render()),'asset replacement invalidates stale review evidence');
$save('embedded');
check(!$show_text($render()),'re-reviewed replacement suppresses repeated text');
$save('plain');
check(count($GLOBALS['purges'])===3,'changed image review triggers scoped purge once per change');
check($show_text($render()),'reviewed plain logo keeps standalone slogan');
$GLOBALS['look']['mad4b_logo_artwork_signoff']='MORE THAN A TRIP ... A WAY OF LIFE.';
check(!$show_text($render()),'approved embedded signoff outranks conflicting plain classification');
$GLOBALS['look']['footer_logo_inverse']='13';
check($show_text($render()),'custom non-brand logo cannot inherit unrelated artwork signoff');
$GLOBALS['look']['footer_logo_inverse']='12';
$GLOBALS['can_edit']=false;
$save('embedded');
check(get_post_meta(12,'_mad4b_brand_logo_slogan_review_v1',true)['status']==='plain',
    'unauthorized reviewer cannot reclassify asset');
$GLOBALS['can_edit']=true;
$save('INVALID');
check(get_post_meta(12,'_mad4b_brand_logo_slogan_review_v1',true)['status']==='plain',
    'invalid review state cannot alter prior evidence');
$GLOBALS['logo_image']=false;
check($show_text($render()) && str_contains($render(),'mad4b-footer-wordmark'),
    'unavailable image falls back to wordmark plus text even with a brand signoff');
$save('unknown');
check(count($GLOBALS['purges'])===4,'resetting image review triggers scoped HTML purge');
check(get_post_meta(12,'_mad4b_brand_logo_slogan_review_v1',true)==='',
    'resetting media review deletes per-asset evidence');
$old=$GLOBALS['look'];
$changed=$old;$changed['mad4b_logo_artwork_signoff']='New artwork signoff';
MAD4B_Footer_LookFeel::brand_options_updated($old,$changed);
check(count($GLOBALS['purges'])===5,'artwork signoff changes invalidate cached footer globally');
MAD4B_Footer_LookFeel::brand_options_updated($old,$old);
check(count($GLOBALS['purges'])===5,'nonchanges do not trigger a purge');
MAD4B_Footer_LookFeel::kit_tagline_updated(1,7,'_elementor_page_settings');
check(count($GLOBALS['purges'])===6,'active Elementor Kit tagline changes invalidate site-wide footer');
MAD4B_Footer_LookFeel::kit_tagline_updated(1,999,'_elementor_page_settings');
check(count($GLOBALS['purges'])===6,'unrelated Elementor page metadata does not trigger purge');
unlink($asset);
echo "PASS: FOOTER_LOGO_SLOGAN_PRIORITY ".$GLOBALS['checks']." source-only assertions\n";
