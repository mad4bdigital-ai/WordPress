<?php
namespace FluentForm\App\Modules\Acl { class Acl {} }
namespace {
 define('ABSPATH','/');
 $GLOBALS['h']=[];
 function add_action($hook,$cb,$priority=10){$GLOBALS['h'][$hook][]=[$cb,$priority];}
 function current_user_can($cap){return in_array($cap,['fluentform_forms_manager','fluentform_entries_viewer','fluentform_dashboard_access'],true);}
 class MAD4B_Admin_Surface_Registry_V1 {
  public static function allowed($slug,$op){return $op==='read' && in_array($slug,['fluent_forms#add=1','fluent_forms_all_entries','fluent_forms_reports'],true);}
 }
 $submenu=['fluent_forms'=>[
  ['New','fluentform_settings_manager','fluent_forms#add=1'],
  ['Entries','fluentform_settings_manager','fluent_forms_all_entries'],
  ['Reports','fluentform_settings_manager','fluent_forms_reports'],
  ['Settings','fluentform_settings_manager','fluent_forms_settings'],
  ['Stripe','fluentform_settings_manager','fluent_forms_add_ons']
 ]];
 $_wp_submenu_nopriv=['fluent_forms'=>[
  'fluent_forms#add=1'=>true,'fluent_forms_all_entries'=>true,
  'fluent_forms_reports'=>true,'fluent_forms_settings'=>true
 ]];
 $path=getenv('MAD4B_FORMS_BRIDGE_SOURCE');if(!$path||!is_file($path))exit(80);
 require $path;
 MAD4B_Fluent_Forms_Scoped_Menus_V1::reconcile();
 $expected=['fluentform_forms_manager','fluentform_entries_viewer','fluentform_entries_viewer','fluentform_settings_manager','fluentform_settings_manager'];
 foreach($submenu['fluent_forms'] as $idx=>$row){if($row[1]!==$expected[$idx])exit(81);}
 foreach(['fluent_forms#add=1','fluent_forms_all_entries','fluent_forms_reports'] as $slug)if(isset($_wp_submenu_nopriv['fluent_forms'][$slug]))exit(82);
 if(!isset($_wp_submenu_nopriv['fluent_forms']['fluent_forms_settings']))exit(83);
 echo "PASS_NATIVE_FORMS_PAGES_SCOPED_AND_PAYMENT_SETTINGS_DENIED\n";
}
