<?php
error_reporting(E_ALL);
define('ABSPATH',sys_get_temp_dir().'/qa20-root/');
// Even explicit host opt-in must not reopen the removed local repository namespace.
define('MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED',true);
class WP_Error {
  private $code;
  public function __construct($code,$message=''){ $this->code=$code; }
  public function get_error_code(){ return $this->code; }
}
function is_wp_error($o){return $o instanceof WP_Error;}
$last_url='';
$network_calls=0;
$network_mode='ok';
function wp_safe_remote_get($url,$args){
  global $last_url,$network_mode,$network_calls;
  $last_url=$url;
  $network_calls++;
  if($network_mode==='fail')return new WP_Error('network_disconnected');
  if($network_mode==='badsha')return ['code'=>200,'body'=>'{"sha":"ffffffffffffffffffffffffffffffffffffffff"}'];
  if($network_mode==='invalid')return ['code'=>200,'body'=>'invalid'];
  preg_match('~/commits/([a-f0-9]{40})$~',$url,$match);
  return ['code'=>200,'body'=>json_encode(['sha'=>$match[1]??''])];
}
function wp_remote_retrieve_response_code($response){return (int)$response['code'];}
function wp_remote_retrieve_body($response){return (string)$response['body'];}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-source-selector.php';
$selector='mad4bdigital-ai/WordPress';$sha=str_repeat('a',40);
$input=['repository'=>$selector,'type'=>'commit','reference'=>$sha];
$r=MAD4B_SCP_Staging_Source_Selector::resolve($input);
if(is_wp_error($r)||$r['resolved_sha']!==$sha||$r['remote_verified']!==true||$r['authorizing']!==false)throw new Exception('allowed_repo_mock_verification_failed');
if($last_url!=='https://api.github.com/repos/'.$selector.'/commits/'.$sha)throw new Exception('not_pinned_github_origin');
$calls_before_denied=$network_calls;
$blocked=MAD4B_SCP_Staging_Source_Selector::resolve(array_merge($input,['repository'=>'mad4b-local/staging']));
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='mad4b_staging_source_repository_denied')throw new Exception('legacy_local_repository_not_blocked');
if($network_calls!==$calls_before_denied)throw new Exception('denied_local_repository_triggered_network');
$blocked=MAD4B_SCP_Staging_Source_Selector::resolve(array_merge($input,['repository'=>'attacker/repository']));
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='mad4b_staging_source_repository_denied')throw new Exception('untrusted_repository_not_blocked');
$network_mode='badsha';
$blocked=MAD4B_SCP_Staging_Source_Selector::resolve($input);
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='mad4b_staging_source_source_sha_invalid')throw new Exception('mismatched_remote_sha_accepted');
$network_mode='fail';
$blocked=MAD4B_SCP_Staging_Source_Selector::resolve($input);
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='mad4b_staging_source_lookup_failed')throw new Exception('network_failure_not_closed');
$network_mode='ok';
$blocked=MAD4B_SCP_Staging_Source_Selector::normalize(array_merge($input,['download_url'=>'https://example.test/file.zip']));
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='mad4b_staging_source_schema_invalid')throw new Exception('extra_download_url_accepted');
echo "QA20_RC118_SOURCE_SELECTOR_PASS\n";
