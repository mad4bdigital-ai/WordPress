<?php
/**
 * Plugin Name: MAD4B Company Option Dynamic Tags
 * Description: Brand-neutral, tenant-only Elementor tag providers, no inherited static branding.
 * Version: 0.1.0
 */
defined('ABSPATH')||exit;
if(!function_exists('mad4b_tenant_brand_media_value')) {
 function mad4b_tenant_brand_media_value(string $role):array {
   $map=['logo_default'=>'company_brand_logo_default',
     'logo_inverse'=>'company_brand_logo_inverse',
     'logo_compact'=>'company_brand_logo_compact',
     'logo_mobile'=>'company_brand_logo_mobile',
     'favicon'=>'company_brand_favicon',
     'pattern'=>'company_brand_pattern_media'];
   if(!isset($map[$role]))return ['id'=>0,'url'=>''];
   $opts=get_option('look-and-feel',[]);
   $raw=is_array($opts)?($opts[$map[$role]]??null):null;
   $id=is_array($raw)?absint($raw['id']??$raw['attachment_id']??0):absint($raw);
   if(!$id || get_post_type($id)!=='attachment')return ['id'=>0,'url'=>''];
   $uploads=wp_get_upload_dir();
   $url=wp_get_attachment_url($id);
   $mime=(string)get_post_mime_type($id);
   $host=is_string($url)?wp_parse_url($url,PHP_URL_HOST):'';
   if(!in_array($mime,['image/jpeg','image/png','image/webp','image/x-icon','image/vnd.microsoft.icon'],true) ||
     !$host || $host!==wp_parse_url($uploads['baseurl'],PHP_URL_HOST))
     return ['id'=>0,'url'=>''];
   return ['id'=>$id,'url'=>esc_url_raw($url)];
 }
}
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('Elementor\Core\DynamicTags\Data_Tag'))return;
 if(!class_exists('MAD4B_Tenant_Brand_Image_Tag',false)){
  class MAD4B_Tenant_Brand_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'company-brand-media';}
   public function get_title(){return 'Company Brand Image (Tenant Options)';}
   public function get_group(){return 'site';}
   public function get_categories(){return ['image'];}
   protected function register_controls(){
    $this->add_control('role',['label'=>'Brand artwork','type'=>\Elementor\Controls_Manager::SELECT,
      'options'=>['logo_default'=>'Default logo','logo_inverse'=>'Inverse logo',
        'logo_compact'=>'Compact logo','logo_mobile'=>'Mobile logo',
        'favicon'=>'Site icon','pattern'=>'Pattern'],'default'=>'logo_default']);
   }
   public function get_value(array $options=[]){return mad4b_tenant_brand_media_value((string)$this->get_settings('role'));}
  }
  class MAD4B_Tenant_Presentation_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'company-presentation-option';}
   public function get_title(){return 'Company Presentation Text (Tenant Options)';}
   public function get_group(){return 'site';}
   public function get_categories(){return ['text'];}
   protected function register_controls(){
    $this->add_control('field',['label'=>'JetEngine Look & Feel field',
      'type'=>\Elementor\Controls_Manager::TEXT]);
   }
   public function get_value(array $options=[]){
    $key=(string)$this->get_settings('field');
    if(!preg_match('/^destination_[a-z0-9_]{1,90}$/D',$key))return '';
    $manager=function_exists('jet_engine')?(jet_engine()->options_pages??null):null;
    $page=is_object($manager)?($manager->registered_pages['look-and-feel']??null):null;
    $known=false;
    foreach((array)($page->page['fields']??[]) as $field){
      if(is_array($field)&&($field['name']??'')===$key&&
        in_array($field['type']??'',['text','textarea','wysiwyg'],true)){$known=true;break;}
    }
    if(!$known)return '';
    $data=get_option('look-and-feel',[]);
    $text=is_array($data)?($data[$key]??''):'';
    if(!is_string($text)||trim($text)==='')return '';
    $lang=(string)apply_filters('wpml_current_language',null);
    if($lang!=='') {
     $translated=apply_filters('wpml_translate_single_string',$text,'company-presentation',$key,$lang);
     if(is_string($translated)&&trim($translated)!=='')$text=$translated;
    }
    return sanitize_text_field($text);
   }
  }
 }
 $manager->register(new MAD4B_Tenant_Brand_Image_Tag());
 $manager->register(new MAD4B_Tenant_Presentation_Tag());
},60);


/** Project tenant-configured brand values into WordPress/Woo core without copying source options. */
add_filter('get_site_icon_url',static function($url){
 $asset=mad4b_tenant_brand_media_value('favicon');
 return !empty($asset['url'])?$asset['url']:$url;
},45,1);
add_filter('theme_mod_custom_logo',static function($existing){
 if(!empty($existing))return $existing;
 $asset=mad4b_tenant_brand_media_value('logo_default');
 return !empty($asset['id'])?$asset['id']:$existing;
},45,1);
add_filter('woocommerce_email_from_name',static function($name){
 $name=is_scalar($name)?trim((string)$name):'';
 // Explicit company-supplied Woo sender name takes precedence.
 $configured=(string)get_option('woocommerce_email_from_name','');
 if(trim($configured)!=='')return $name;
 $site=trim((string)get_option('blogname',''));
 return $site!==''?$site:$name;
},45,1);
