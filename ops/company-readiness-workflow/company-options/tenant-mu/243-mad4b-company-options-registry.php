<?php
/**
 * Plugin Name: MAD4B Company Configuration Registry
 * Description: Company-scoped JetEngine Options Page authoring and centralized runtime schema status.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Options_Registry_UI',false)) {
final class MAD4B_Company_Options_Registry_UI {
 const REGISTRY='mad4b_company_configuration_registry_v2';
 const CAPABILITY='mad4b_manage_company_operations';
 const OWNER_PAGES=['contact-and-help-info','look-and-feel','global-offers','company-communication-operations'];
 public static function init(){
   add_action('init',[__CLASS__,'scope_jetengine_pages'],999);
   add_action('admin_menu',[__CLASS__,'register_admin'],100);
   add_action('rest_api_init',[__CLASS__,'register_rest']);
 }
 public static function allowed(){
   return current_user_can('manage_options')||current_user_can(self::CAPABILITY);
 }
 public static function scope_jetengine_pages(){
   if(!function_exists('jet_engine'))return;
   $manager=jet_engine()->options_pages??null;
   if(!is_object($manager))return;
   foreach(self::OWNER_PAGES as $slug) {
     $obj=$manager->registered_pages[$slug]??null;
     if(!is_object($obj)||!is_array($obj->page??null))continue;
     // Only four company-editable pages; never grant broad manage_options.
     $obj->page['capability']=self::CAPABILITY;
   }
 }
 private static function registry(){
   $r=get_option(self::REGISTRY,[]);
   return is_array($r)&&($r['contract']??'')==='mad4b.company-configuration-registry.v2'
     &&!empty($r['fingerprint'])&&is_array($r['pages']??null)?$r:null;
 }
 public static function summary(){
   $r=self::registry();
   $identity=get_option('mad4b_company_site_identity_v1',[]);
   $result=['contract'=>'mad4b.company-configuration-status.v2',
    'company'=>sanitize_key((string)($identity['company_code']??'')),
    'registry_fingerprint'=>$r['fingerprint']??null,
    'definition_authority'=>'central_control_plane_runtime',
    'value_authority'=>'tenant_site_jetengine_page_options',
    'state'=>$r?'READY_FOR_COMPANY_INPUT':'REGISTRY_NOT_AVAILABLE',
    'pages'=>[],'totals'=>['defined'=>0,'editable'=>0,'pending'=>0,'configured'=>0,'missing_provider'=>0]];
   if(!$r)return $result;
   $manager=function_exists('jet_engine')?(jet_engine()->options_pages??null):null;
   foreach($r['pages'] as $slug=>$page){
      if(!preg_match('/^[a-z0-9-]+$/D',(string)$slug))continue;
      $kind=(string)($page['classification']??'platform_managed');
      $instance=is_object($manager)?($manager->registered_pages[$slug]??null):null;
      $current=is_object($instance)&&is_array($instance->page??null)?$instance->page:[];
      $declared=(array)($current['fields']??[]);
      $currentFields=[];
      foreach($declared as $f)if(is_array($f)&&!empty($f['name']))
          $currentFields[$f['name']]=(string)($f['type']??'');
      $option=get_option($slug,null);
      $fields=[];$pending=0;$configured=0;$missing=0;$editable=0;
      foreach($page['fields']??[] as $f){
        $key=(string)($f['key']??'');
        if(!$key)continue;
        $type=(string)($f['type']??'');
        $structural=in_array($type,['html','tab','accordion','endpoint'],true);
        $provider=isset($currentFields[$key])&&$currentFields[$key]===$type;
        $isEditable=$kind==='company_editable'&&!$structural&&$provider;
        $filled=is_array($option)&&array_key_exists($key,$option)
          &&$option[$key]!==null&&$option[$key]!==''&&$option[$key]!==[];
        if(!$provider)$missing++;
        if($isEditable){$editable++;if($filled)$configured++;else $pending++;}
        $fields[]=['key'=>$key,'label'=>(string)($f['label']??$key),'type'=>$type,
          'provider'=>$provider?'READY':'MISSING_OR_DIFFERENT',
          'state'=>!$provider?'UNAVAILABLE':
            ($structural?'STRUCTURAL':($filled?'CONFIGURED':'EMPTY_FOR_CUSTOMIZATION'))];
      }
      $canEdit=($kind==='company_editable'||$kind==='approval_required')
        &&is_object($instance)&&current_user_can((string)($current['capability']??'manage_options'));
      $result['pages'][$slug]=[
        'classification'=>$kind,'defined_count'=>count($fields),
        'configured_count'=>$configured,'pending_count'=>$pending,'editable_count'=>$editable,
        'missing_provider_count'=>$missing,
        'editor'=>$canEdit?admin_url('admin.php?page='.$slug):null,
        'fields'=>$fields];
      $result['totals']['defined']+=count($fields);
      $result['totals']['editable']+=$editable;
      $result['totals']['pending']+=$pending;
      $result['totals']['configured']+=$configured;
      $result['totals']['missing_provider']+=$missing;
   }
   return $result;
 }
 public static function register_rest(){
   register_rest_route('mad4b/v1','/company-configuration/fields',[
    'methods'=>WP_REST_Server::READABLE,
    'permission_callback'=>[__CLASS__,'allowed'],
    'callback'=>static function(){$r=new WP_REST_Response(self::summary(),200);
       $r->header('Cache-Control','private, no-store');return $r;}]);
 }
 public static function register_admin(){
   add_menu_page('Company Configuration','Company Configuration',self::CAPABILITY,
    'mad4b-company-configuration',[__CLASS__,'render'],'dashicons-admin-generic',65);
 }
 public static function render(){
   if(!self::allowed())wp_die('Permission denied');
   $r=self::summary();
   echo '<div class="wrap"><h1>Company Configuration</h1><p>Central definitions; tenant-owned JetEngine values. Source values are never imported.</p>';
   if($r['state']!=='READY_FOR_COMPANY_INPUT'){echo '<p>Registry v2 is not connected.</p></div>';return;}
   echo '<table class="widefat striped"><thead><tr><th>JetEngine Options Page</th><th>Authority</th><th>Ready fields</th><th>Pending</th><th>Provider gaps</th><th>Manage</th></tr></thead><tbody>';
   foreach($r['pages'] as $name=>$p) {
    echo '<tr><td><code>'.esc_html($name).'</code></td><td>'.esc_html($p['classification']).'</td>';
    echo '<td>'.esc_html((string)$p['editable_count']).'</td><td>'.esc_html((string)$p['pending_count']).'</td><td>'.esc_html((string)$p['missing_provider_count']).'</td>';
    echo '<td>'.($p['editor']?'<a class="button" href="'.esc_url($p['editor']).'">Edit in JetEngine</a>':'—').'</td></tr>';
   }
   echo '</tbody></table><p>Blank editable fields are expected until this company enters its own values. Approval-required and platform-managed options do not inherit source values.</p></div>';
 }
}
MAD4B_Company_Options_Registry_UI::init();
}

