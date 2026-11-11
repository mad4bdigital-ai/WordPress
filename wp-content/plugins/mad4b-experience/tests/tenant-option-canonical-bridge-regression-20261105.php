<?php
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='staging'||
   untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
   throw new RuntimeException('CENTRAL_STAGING_TEST_ONLY');
$cls='MAD4B_Tenant_Option_Canonical_Bridge_V1';
if(!class_exists($cls,false))throw new RuntimeException('PORTABLE_BRIDGE_NOT_LOADED');
$passed=0;
$assert=static function($ok,string $name)use(&$passed):void {
    if(!$ok)throw new RuntimeException('FAILED:'.$name);
    $passed++;echo 'PASS '.$name.PHP_EOL;
};
$assert(($cls::contract()['pricing_writable']??true)===false,'commerce_owner_protected');
$assert(($cls::contract()['legacy_must_be_detached_after_exact_target_readback']??false)===true,
        'legacy_detachment_requires_target_proof');
$assert(strlen($cls::manifest_digest())===64,'portable_contract_digest');
$assert(!class_exists('Wayfa_Dynamic_Page_Options',false),'source_has_no_wayfa_editor');
$assert(!$cls::operating(),'source_demo_bridge_inactive');
$assert(!$cls::legacy_present(),'no_legacy_mu_source_owner');
$assert(class_exists('Wayfa_Options_Source_Bridge',false) &&
 is_subclass_of('Wayfa_Options_Source_Bridge',$cls),'old_editor_api_alias_available');
$assert($cls::is_readonly('site','brand_name','en'),'read_only_without_tenant_editor');
$assert(($cls::health()['state']??'')==='TENANT_ADAPTER_NOT_CONNECTED',
    'source_noncompany_health_not_falsely_connected');
$registry=[
 'site'=>[
   'brand_name'=>['text','text',true],
   'tagline'=>['text','text',true],
   'contact_email'=>['text','email',false],
   'instagram_url'=>['text','url',false],
   'primary_cta_text'=>['text','text',true],
   'default_currency'=>['text','currency',false]
 ],
 'header_footer'=>[
   'footer_template'=>['text','template',true],
   'newsletter_template'=>['text','template',true]
 ],
 'popup'=>['announcement_text'=>['text','text',true]],
];
class Wayfa_Dynamic_Page_Options {
 public static function scope():bool{return true;}
 public static function definitions(string $group):array {
   return $GLOBALS['mad4b_tenant_bridge_fixture_schemas'][$group]??[];
 }
}
$GLOBALS['mad4b_tenant_bridge_fixture_schemas']=$registry;
$assert($cls::operating(),'test_fixture_can_read_no_write');
$assert(!$cls::is_readonly('site','brand_name','en'),'primary_brand_editor_connected');
$assert($cls::is_readonly('site','brand_name','es'),'spanish_brand_name_not_unapproved');
$assert($cls::is_readonly('site','tagline','es'),'spanish_tagline_not_unapproved');
$assert($cls::is_readonly('site','default_currency','en'),'commerce_currency_not_writable');
$assert($cls::is_readonly('popup','announcement_text','en'),'unbound_popup_read_only');
$assert($cls::is_readonly('header_footer','footer_template','en'),'site_footer_owner_not_guessed');
$assert($cls::is_readonly('site','primary_cta_text','en'),'cta_requires_unique_exact_binding');
$assert($cls::is_readonly('header_footer','newsletter_template','en'),
        'newsletter_requires_unique_home_widget_binding');
$kit=get_post_meta((int)get_option('elementor_active_kit'),'_elementor_page_settings',true);
$name=(string)($kit['site_name']??get_option('blogname'));
$assert($cls::read('site','brand_name',0,'en')===$name,'brand_reads_elementor_active_kit');
$assert($cls::read('site','tagline',0,'es')==='','spanish_unapproved_tagline_not_copied');
$assert($cls::read('site','default_currency',0,'en')===(string)get_option('woocommerce_currency','USD'),
        'woocommerce_currency_reads_native_option');
$jet=get_option('contact-and-help-info',[]);
$assert($cls::read('site','contact_email',0,'en')===(string)($jet['site_email']??''),
        'jetengine_contact_email_canonical');
$assert($cls::read('site','instagram_url',0,'en')===(string)($jet['site_instagram_page_link']??''),
        'jetengine_social_canonical');
$old=['brand_name__en'=>$name];
$next=['brand_name__en'=>'Proof Brand Not Saved'];
$before=get_option('blogname');
$p=$cls::prepare('site','en',$old,$next);
$assert(is_array($p)&&($p['change_fields']??[])===['brand_name'] &&
 ($p['writes_performed']??true)===false,'validated_brand_plan_does_not_write');
$assert(get_option('blogname')===$before,'brand_plan_never_mutates_wordpress');
$bad=$cls::prepare('site','en',$old,['brand_name__en'=>'X']);
$assert(is_wp_error($bad) && $bad->get_error_code()==='mad4b_tenant_canonical_invalid_value',
    'invalid_brand_name_rejected');
$broken=$cls::prepare('site','en',['brand_name__en'=>'Outdated Brand'],
 ['brand_name__en'=>'New Proposed Brand']);
$assert(is_wp_error($broken) &&
 $broken->get_error_code()==='mad4b_tenant_canonical_stale_alias',
    'stale_duplicate_editor_value_rejected');
$contact=$cls::prepare('site','en',[],['instagram_url'=>'https://random.example/brand']);
$assert(is_wp_error($contact) &&
 $contact->get_error_code()==='mad4b_tenant_canonical_invalid_value',
    'unapproved_social_host_rejected');
$currency=$cls::prepare('site','en',[],
 ['default_currency'=>'EGP']);
$assert(is_array($currency)&&empty($currency['changes'])&&
 ($currency['no_pricing_write']??false)===true,'currency_changes_ignored_by_bridge');
$popup=$cls::prepare('popup','en',[],['announcement_text__en'=>'Unsupported']);
$assert(is_array($popup)&&empty($popup['changes']),
    'unsupported_popup_cannot_write_into_any_provider');
$forbidden=$cls::commit('site','en',$old,$next);
$assert(is_wp_error($forbidden) &&
 $forbidden->get_error_code()==='mad4b_tenant_bridge_admin_nonce_required',
 'direct_wpcli_cannot_write_canonical_settings');
$assert(get_option('blogname')===$before,'no_mutation_after_forbidden_write');
echo 'VERDICT TENANT_OPTION_CANONICAL_SOURCE_REGRESSION '.(string)$passed.'/'.(string)$passed.' PASS'.PHP_EOL;
