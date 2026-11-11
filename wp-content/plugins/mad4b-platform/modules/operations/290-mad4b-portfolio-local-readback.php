<?php
/**
 * Plugin Name: MAD4B Portfolio Local Identity Readback (Staging)
 * Description: Stores short-lived root-VPS WP-CLI readback evidence for enrolled sites; never grants remote access or authority.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Portfolio_Local_Readback_V1 {
  const CONTRACT='mad4b.portfolio.local-readback.v1';
  const PREFIX='mad4b_portfolio_local_readback_v1_';
  const TTL=3600;
  private static function local_owner():bool {
    return defined('WP_CLI') && WP_CLI && get_current_user_id()>0
      && current_user_can('manage_options')
      && class_exists('MAD4B_SCP_Site_Profile',false)
      && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
  }
  private static function site():bool {
    return wp_get_environment_type()==='staging'
      && home_url('/')==='https://tours.dev.mad4b.com/'
      && class_exists('MAD4B_Central_Company_REST_V1',false);
  }
  private static function key($id):string {return self::PREFIX.str_replace('-','_',$id);}
  private static function expected($id) {
    if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id))return false;
    $dir=MAD4B_Central_Company_REST_V1::directory();
    if(empty($dir[$id]['provisioning_verified']))return false;
    $reg=get_option('mad4b_tourism_company_site_registry_v1',[]);
    $r=is_array($reg)?($reg[$id]??null):null;
    if(!is_array($r)||($r['company_code']??'')!==$id
       ||empty($r['host_binding_verified']))return false;
    $site=(string)($r['company_site_uuid']??'');
    $profile=(string)($r['native_site_profile_uuid']??'');
    $url=(string)($r['canonical_origin']??'');
    if(!wp_is_uuid($site)||!wp_is_uuid($profile)||
       $url!==$dir[$id]['url'])return false;
    return ['company_id'=>$id,'site_uuid'=>$site,
            'profile_uuid'=>$profile,'canonical_origin'=>$url];
  }
  private static function sign(array $v):string {
    return hash_hmac('sha256',wp_json_encode($v,JSON_UNESCAPED_SLASHES),wp_salt('auth'));
  }
  public static function verified($id) {
    if(!self::site())return false;
    $expected=self::expected($id);
    if(!$expected)return false;
    $v=get_option(self::key($id),false);
    if(!is_array($v)||!is_array($v['readback']??null)||
       !is_string($v['signature']??null))return false;
    $r=$v['readback'];
    if(!hash_equals(self::sign($r),$v['signature'])||
       ($r['contract']??'')!==self::CONTRACT||
       ($r['source_method']??'')!=='root_vps_wp_cli_read_only'||
       ($r['write_granted']??true)!==false||
       !in_array(($r['target_environment']??''),['staging','production'],true)||
       !preg_match('/^[a-f0-9]{64}$/D',(string)($r['readback_sha256']??'')))return false;
    foreach($expected as $k=>$val)
      if(!is_string($r[$k]??null)||!hash_equals($val,$r[$k]))return false;
    $time=(int)($r['observed_at_epoch']??0);
    if($time<time()-self::TTL||$time>time()+30)return false;
    return $r;
  }
  public static function store(array $e) {
    if(!self::site()||!self::local_owner())
      return new WP_Error('mad4b_local_readback_owner_required','Enrolled WP-CLI owner required.');
    $id=$e['company_id']??null;
    $expected=self::expected($id);
    if(!$expected)return new WP_Error('mad4b_local_readback_company_unknown','Unknown enrolled company.');
    foreach($expected as $k=>$v)
      if(!is_string($e[$k]??null)||!hash_equals($v,$e[$k]))
        return new WP_Error('mad4b_local_readback_mismatch','Site identity mismatch.');
    if(!in_array(($e['target_environment']??''),['staging','production'],true)||
       !preg_match('/^[a-f0-9]{64}$/D',(string)($e['readback_sha256']??''))||
       abs(time()-(int)($e['observed_at_epoch']??0))>120)
      return new WP_Error('mad4b_local_readback_invalid','Invalid or stale readback.');
    $container=$e['container']??'';
    if($container!==$id.'-production-php'&&$container!==$id.'-staging-php')
      return new WP_Error('mad4b_local_readback_wrong_container','Untrusted target container.');
    $r=$expected+[
      'contract'=>self::CONTRACT,
      'source_method'=>'root_vps_wp_cli_read_only',
      'target_environment'=>$e['target_environment'],
      'container'=>$container,
      'readback_sha256'=>$e['readback_sha256'],
      'observed_at_epoch'=>(int)$e['observed_at_epoch'],
      'write_granted'=>false];
    $row=['readback'=>$r,'signature'=>self::sign($r)];
    $key=self::key($id);
    $saved=update_option($key,$row,false);
    if(!$saved && get_option($key,false)!==$row)
      return new WP_Error('mad4b_local_readback_store_failed','Readback receipt not saved.');
    $verified=self::verified($id);
    if(!$verified)return new WP_Error('mad4b_local_readback_verify_failed','Independent receipt verification failed.');
    return ['company_id'=>$id,'local_readback_verified'=>true,
      'target_environment'=>$r['target_environment'],
      'signed_remote_pairing'=>false,
      'remote_write_granted'=>false];
  }
  public static function cli($args,$assoc):void {
    $raw=base64_decode((string)($assoc['readback']??''),true);
    $input=is_string($raw)&&strlen($raw)<=1800?json_decode($raw,true):null;
    if(!is_array($input))WP_CLI::error('Invalid local readback input.');
    $r=self::store($input);
    if(is_wp_error($r))WP_CLI::error($r->get_error_code());
    WP_CLI::line(wp_json_encode($r));
  }
  public static function boot():void {
    if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
      WP_CLI::add_command('mad4b portfolio record-local-readback',[__CLASS__,'cli']);
  }
}
MAD4B_Portfolio_Local_Readback_V1::boot();

