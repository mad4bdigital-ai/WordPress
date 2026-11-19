<?php
namespace FluentCrm\App\Services {
 class PermissionManager {
  public static function pluginPermissions(){
   return ['fcrm_view_dashboard','fcrm_read_contacts','fcrm_manage_contacts',
    'fcrm_manage_contacts_delete','fcrm_manage_contacts_export',
    'fcrm_manage_contact_cats','fcrm_manage_contact_cats_delete',
    'fcrm_read_emails','fcrm_manage_emails','fcrm_manage_email_delete',
    'fcrm_manage_email_templates','fcrm_manage_forms','fcrm_read_funnels',
    'fcrm_write_funnels','fcrm_delete_funnels','fcrm_manage_settings'];
  }
 }
}
namespace {
 define('ABSPATH','/');
 function add_action($tag,$cb,$priority=10){}
 $GLOBALS['revoked']=[];
 $GLOBALS['native_meta']=null;
 function user_can($id,$cap){return !in_array($cap,$GLOBALS['revoked'],true);}
 function fluentcrm_get_meta($user,$class,$key){return $GLOBALS['native_meta'];}
 $path=getenv('MAD4B_REGISTRY_BRIDGE_SOURCE');if(!$path||!is_file($path))exit(87);
 require $path;
 $user=(object)['ID'=>123,'roles'=>['master_admin']];
 $class='MAD4B_Admin_Surface_Registry_V1';
 $all=$class::fluentcrm_permissions([], $user);
 if(count($all)!==16)exit(88);
 $GLOBALS['revoked']=['commerce_manage_crm'];
 if($class::fluentcrm_permissions([], $user)!==[])exit(89);
 $GLOBALS['revoked']=['commerce_manage_policies'];
 $part=$class::fluentcrm_permissions([], $user);
 if(count($part)!==15 || in_array('fcrm_manage_settings',$part,true))exit(90);
 $GLOBALS['revoked']=[];
 $GLOBALS['native_meta']=(object)['value'=>[]];
 if($class::fluentcrm_permissions([], $user)!==[])exit(91);
 $GLOBALS['native_meta']=(object)['value'=>['fcrm_read_contacts']];
 if($class::fluentcrm_permissions([], $user)!==['fcrm_read_contacts'])exit(92);
 echo "PASS_CRM_URE_CAP_REVOCATION_AND_NATIVE_META_UPPER_BOUND\n";
}
