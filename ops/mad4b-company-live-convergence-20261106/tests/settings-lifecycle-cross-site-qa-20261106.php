<?php
/** Read-only lifecycle acceptance for a site-local MAD4B Control Plane. */
require '/var/www/html/wp-load.php';
$assert = [];
$obs = [];
$test = static function(string $name, bool $pass, string $detail='') use (&$assert):void {
    $assert[$name]=['pass'=>$pass,'detail'=>$detail];
};
$obs['site']=home_url('/');
$obs['environment']=wp_get_environment_type();
$obs['checked_at']=gmdate('c');
$alignment='MAD4B_Company_Settings_Alignment_V1';
$rate='MAD4B_Tour_Rate_View_Inheritance_V1';
$registry='MAD4B_SCP_Skill_Registry';
$seeder='MAD4B_SCP_Skill_Seeder';
$profile='MAD4B_SCP_Site_Profile';
$pages=class_exists($alignment,false)?$alignment::jet_pages():[];
$domains=class_exists($alignment,false)?$alignment::snapshot():[];
$test('jet_options_discovery',count($pages)>=11,'pages='.count($pages));
$test('settings_alignment_catalog',count($domains)>=13,'domains='.count($domains));
$opts=get_option('jet_engine_meta_boxes',[]);
$fields=$opts['mad4b_tour_layout_page_options']['meta_fields']??[];
$overrides=count(array_filter((array)$fields,static fn($f)=>($f['name']??'')==='mad4b_rate_view_override_v1'));
$test('native_tour_override_field', $overrides===1,'fields='.$overrides);
$test('render_policy_class',class_exists($rate,false));
$test('no_admin_get_schema_write',false===has_action('admin_init',[$rate,'register_tour_editor']));
$render=$GLOBALS['wp_filter']['elementor/frontend/widget/before_render']??null;
$priorities=[];
if($render instanceof WP_Hook)foreach($render->callbacks as $priority=>$callbacks){foreach($callbacks as $cb){if(($cb['function']??null)==[$rate,'before_widget'])$priorities[]=(int)$priority;}}
$test('single_elementor_query_router',$priorities===[50],'priorities='.implode(',',$priorities));
$test('woo_currency_available',(bool)preg_match('/^[A-Z]{3}$/',(string)get_option('woocommerce_currency','')));
$obs['woo_currency']=(string)get_option('woocommerce_currency','');
$obs['woo_tax_enabled']=get_option('woocommerce_calc_taxes','no')==='yes';
$obs['woocommerce_checkout_guest']=get_option('woocommerce_enable_guest_checkout','no');
$senderHooks=['woocommerce_email_from_address','woocommerce_email_from_name','woocommerce_email_recipient_new_order'];
$obs['email_hooks']=[];
foreach($senderHooks as $hook)$obs['email_hooks'][$hook]=(bool)has_filter($hook);
$test('email_adapter_bound',!in_array(false,$obs['email_hooks'],true),'hooks='.implode(',',array_keys(array_filter($obs['email_hooks']))));
$obs['company_communications_class_loaded']=class_exists('MAD4B_SCP_Company_Communication',false);
$obs['company_operations_class_loaded']=class_exists('MAD4B_SCP_Company_Operations_Policy',false);
$obs['company_master_admin_loaded']=class_exists('MAD4B_SCP_Company_Master_Admin',false);
$comm_source=get_option('company-communication-operations',[]);
$obs['company_sender_configured']=is_array($comm_source)&&!empty($comm_source['transactional_from_email']);
$obs['company_send_ready']=$obs['company_communications_class_loaded']?(bool)MAD4B_SCP_Company_Communication::canSend():false;
$sp=$GLOBALS['sitepress']??null;
$obs['wpml_native_query_hook']=is_object($sp)&&(false!==has_action('wp',[$sp,'set_wp_query']));
$test('wpml_query_hook',$obs['wpml_native_query_hook']);
if($obs['site']==='https://wayfatours.com/')$tour=60629;
else{$p=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish','numberposts'=>1,'suppress_filters'=>false]);$tour=$p?(int)$p[0]->ID:0;}
$obs['sample_tour']=$tour;
if($tour && get_post_type($tour)==='tours-and-activities'){
 $base=$rate::resolve($tour);
 $look=get_option('look-and-feel',[]);$look=is_array($look)?$look:[];
 $mode=(string)get_post_meta($tour,'tour_mode',true);
 $look['mad4b_rate_view_mode_'.$mode]='grid';
 $look['mad4b_rate_view_precedence_v1']='apply_to_existing';
 $test('view_policy_resolution',in_array($base['style']??'',['accordion','grid'],true),'actual='.$base['style']);
 $force=$rate::resolve($tour,$look);
 $override=(string)get_post_meta($tour,'mad4b_rate_view_override_v1',true);
 $expected=$override==='accordion'?'accordion':($override==='grid'?'grid':'grid');
 $test('mode_policy_simulated',($force['style']??'')===$expected,'force='.$force['style'].' explicit_override='.$override);
 $obs['sample_tour_style']=$base['style'];
 $obs['sample_tour_effective_source']=$base['source'];
 $obs['sample_tour_mode']=$mode;
}else $test('tour_sample_available',false,'No native Tour in sample');
if(class_exists($seeder,false)){
 $state=$seeder::inspect();
 $obs['skills_pack_version']=(int)($state['seed_version']??0);
 $obs['skills_seed_ready']=!empty($state['ready']);
 $obs['skills_to_create']=count($state['would_create']??[]);
 $obs['skills_conflicts']=count($state['conflicts']??[]);
 $obs['skills_enabled']=$profile::skills_enabled();
 $obs['skills_editor_enabled']=$registry::editor_enabled();
 $new=$registry::list_skills(['level'=>'workflow','target'=>'settings-lifecycle']);
 $obs['settings_lifecycle_skill_registered']=count($new)===1 && !empty($new[0]['enabled']);
 $test('skill_packaged_current',$obs['skills_pack_version']===17);
 if($obs['skills_enabled'])$test('runtime_skill_registered',$obs['settings_lifecycle_skill_registered'] && $obs['skills_seed_ready']);
}
$obs['checks']=$assert;
$obs['failures']=array_keys(array_filter($assert,static fn($row)=>!$row['pass']));
$obs['test_result']=!empty($obs['failures'])?'FAIL':((!empty($obs['skills_enabled'])&&!empty($obs['skills_seed_ready']))?'PASS':'PASS_WITH_SKILLS_RUNTIME_BLOCKER');
echo wp_json_encode($obs,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
